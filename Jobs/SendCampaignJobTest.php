<?php

namespace Spatie\Mailcoach\Tests\Jobs;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Mailcoach\Enums\CampaignStatus;
use Spatie\Mailcoach\Events\CampaignSentEvent;
use Spatie\Mailcoach\Exceptions\CouldNotSendCampaign;
use Spatie\Mailcoach\Jobs\SendCampaignJob;
use Spatie\Mailcoach\Mails\CampaignMail;
use Spatie\Mailcoach\Models\Campaign;
use Spatie\Mailcoach\Models\EmailList;
use Spatie\Mailcoach\Models\Send;
use Spatie\Mailcoach\Models\Subscriber;
use Spatie\Mailcoach\Tests\Factories\CampaignFactory;
use Spatie\Mailcoach\Tests\TestCase;
use Spatie\Snapshots\MatchesSnapshots;

class SendCampaignJobTest extends TestCase
{
    use MatchesSnapshots;

    private Campaign $campaign;

    public function setUp(): void
    {
        parent::setUp();

        $this->campaign = (new CampaignFactory())
            ->withSubscriberCount(3)
            ->create();

        $this->campaign->emailList->update(['campaign_mailer' => 'some-mailer']);

        Mail::fake();
    }

    /** @test */
    public function it_can_send_a_campaign_with_the_correct_mailer()
    {
        Event::fake();

        dispatch(new SendCampaignJob($this->campaign));

        Mail::assertSent(CampaignMail::class, 3);
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->mailer === 'some-mailer');

        Event::assertDispatched(CampaignSentEvent::class, function (CampaignSentEvent $event) {
            $this->assertEquals($this->campaign->id, $event->campaign->id);

            return true;
        });

        $this->campaign->refresh();
        $this->assertEquals(CampaignStatus::SENT, $this->campaign->status);
        $this->assertEquals(3, $this->campaign->sent_to_number_of_subscribers);
    }

    /** @test */
    public function it_will_not_create_mailcoach_sends_if_they_already_have_been_created()
    {
        Event::fake();

        $emailList = factory(EmailList::class)->create();

        $campaign = factory(Campaign::class)->create([
            'email_list_id' => $emailList->id,
        ]);

        $subscriber = factory(Subscriber::class)->create([
            'email_list_id' => $emailList->id,
            'subscribed_at' => now(),
        ]);

        factory(Send::class)->create([
            'subscriber_id' => $subscriber->id,
            'campaign_id' => $campaign->id,
        ]);

        dispatch(new SendCampaignJob($campaign));

        $this->assertCount(1, Send::all());
    }

    /** @test */
    public function a_campaign_that_was_sent_will_not_be_sent_again()
    {
        Event::fake();

        $this->assertFalse($this->campaign->wasAlreadySent());
        dispatch(new SendCampaignJob($this->campaign));
        $this->assertTrue($this->campaign->refresh()->wasAlreadySent());
        Mail::assertSent(CampaignMail::class, 3);

        dispatch(new SendCampaignJob($this->campaign));
        Mail::assertSent(CampaignMail::class, 3);
        Event::assertDispatched(CampaignSentEvent::class, 1);
    }

    /** @test */
    public function it_will_prepare_the_webview()
    {
        Event::fake();

        $this->campaign->update([
            'html' => 'my html',
            'webview_html' => null,
        ]);

        dispatch(new SendCampaignJob($this->campaign));

        $this->assertMatchesHtmlSnapshotWithoutWhitespace($this->campaign->refresh()->webview_html);
    }

    /** @test */
    public function it_will_not_send_invalid_html()
    {
        Event::fake();

        $this->campaign->update([
            'track_clicks' => true,
            'html' => '<qsdfqlsmdkjm><<>><<',
        ]);

        $this->expectException(CouldNotSendCampaign::class);

        dispatch(new SendCampaignJob($this->campaign));
    }

    /** @test */
    public function the_queue_of_the_send_campaign_job_can_be_configured()
    {
        Event::fake();

        Queue::fake();

        config()->set('mailcoach.perform_on_queue.send_campaign_job', 'custom-queue');

        $campaign = factory(Campaign::class)->create();
        dispatch(new SendCampaignJob($campaign));

        Queue::assertPushedOn('custom-queue', SendCampaignJob::class);
    }

    /** @test */
    public function regular_placeholders_in_the_subject_will_be_replaced()
    {
        $campaign = (new CampaignFactory())
            ->withSubscriberCount(1)
            ->create([
                'subject' => 'This is a mail sent to ::list.name::',
            ]);

        $campaign->emailList->update(['name' => 'my list']);

        dispatch(new SendCampaignJob($campaign));

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            $this->assertEquals("This is a mail sent to my list", $mail->subject);

            return true;
        });
    }

    /** @test */
    public function personalized_placeholders_in_the_subject_will_be_replaced()
    {
        $campaign = (new CampaignFactory())
            ->create([
                'subject' => 'This is a mail sent to ::subscriber.email::',
            ]);


        $subscriber = Subscriber::createWithEmail('john@example.com')
            ->skipConfirmation()
            ->subscribeTo($campaign->emailList);


        dispatch(new SendCampaignJob($campaign));

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) use ($subscriber) {
            $this->assertEquals("This is a mail sent to {$subscriber->email}", $mail->subject);

            return true;
        });
    }
}
