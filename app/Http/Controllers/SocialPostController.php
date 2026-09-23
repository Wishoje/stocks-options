<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSocialDraft;
use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\SocialSetting;
use App\Support\MarketSession;
use App\Support\Social\SocialWorkflow;
use App\Support\Social\XPublisher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SocialPostController extends Controller
{
    public function index(XPublisher $x)
    {
        $date = \App\Support\Social\SocialSchedule::manualSession();

        return Inertia::render('Admin/SocialPosts', [
            'posts' => SocialPost::orderByDesc('session_date')->orderBy('slot')->limit(30)->get()->map(function ($post) {
                $data = $post->toArray();
                $workflow = app(SocialWorkflow::class);
                $data['can_acknowledge_missing_inputs'] = $workflow->canAcknowledgeMissingInputs($post);
                $data['review_token'] = $workflow->reviewToken($post);
                $data['snapshot'] = $post->snapshot ? array_intersect_key($post->snapshot, array_flip(['data_date', 'expiration_dates', 'social_quality'])) : null;

                return $data;
            }),
            'settings' => SocialSetting::current(),
            'defaultDate' => $date,
            'automaticSpyEnabled' => (bool) config('social.automatic_spy_enabled'),
            'localReview' => app()->environment('local') && filled(config('ui_review.now')),
            'connectionConfigured' => $x->configured(),
            'publishingEnabled' => (bool) config('social.publishing_enabled'),
            'scheduleEnabled' => (bool) config('social.schedule_enabled'),
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate(['session_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/New_York')->toDateString(), 'before_or_equal:'.now('America/New_York')->addDays(7)->toDateString()], 'slot' => ['required', Rule::in(['primary', 'secondary', 'qqq', 'tsla'])]]);
        if (! MarketSession::isTradingDay(CarbonImmutable::parse($data['session_date'], 'America/New_York'))) {
            throw ValidationException::withMessages(['session_date' => 'Choose a market trading day.']);
        }
        GenerateSocialDraft::dispatch($data['session_date'], $data['slot']);

        return back()->with('status', 'Draft generation queued. Refresh to see the result.');
    }

    public function update(Request $request, SocialPost $post, SocialWorkflow $workflow)
    {
        $data = $request->validate(['body' => 'required|string|max:2000', 'alt_text' => 'required|string|max:1000']);
        $this->run(fn () => $workflow->edit($post, $data['body'], $data['alt_text']));

        return back()->with('status', 'Draft saved. Review and approve the updated content.');
    }

    public function approve(Request $request, SocialPost $post, SocialWorkflow $workflow)
    {
        $request->validate(['acknowledge_missing_inputs' => 'sometimes|boolean', 'review_token' => 'nullable|string|size:64']);
        $this->run(fn () => $workflow->approve($post, $request->user()->id, $request->boolean('acknowledge_missing_inputs'), $request->input('review_token')));

        return back()->with('status', 'Approved. Choose Send now to publish immediately. Scheduled sending applies only to SPY on Sunday, Tuesday, and Thursday mornings.');
    }

    public function publish(SocialPost $post)
    {
        abort_unless(config('social.publishing_enabled') && ! SocialSetting::current()->paused, 409, 'Publishing is disabled or paused.');
        abort_unless($post->status === 'approved', 409, 'Approve the current draft first.');
        PublishSocialPost::dispatch($post->id);

        return back()->with('status', 'Publish check queued. The worker will enforce the session, freshness, and time window.');
    }

    public function sendNow(Request $request, SocialPost $post, SocialWorkflow $workflow)
    {
        $data = $request->validate(['review_token' => 'required|string|size:64']);
        abort_unless(config('social.publishing_enabled') && ! SocialSetting::current()->paused, 409, 'Publishing is disabled or paused.');
        $result = $this->run(fn () => $workflow->sendNow($post, (int) $request->user()->id, $data['review_token']));

        return back()->with('status', $result->status === 'published' ? 'Posted to @GexOptions.' : 'X did not confirm publication. Review the draft status before retrying.');
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['second_symbol' => ['sometimes', Rule::in(['QQQ', 'TSLA'])], 'paused' => 'required|boolean']);
        $settings = SocialSetting::current();
        if (isset($data['second_symbol']) && $settings->second_symbol !== $data['second_symbol']) {
            SocialPost::where('slot', 'secondary')->where('session_date', '>=', now('America/New_York')->toDateString())
                ->whereIn('status', ['draft', 'approved'])->update(['status' => 'blocked', 'approved_at' => null, 'approved_by' => null, 'quality_acknowledgment' => null,
                    'issue' => 'Second symbol changed. Regenerate the draft to use the new selection.']);
        }
        $settings->update($data);

        return back()->with('status', 'Schedule preferences saved.');
    }

    public function image(SocialPost $post, SocialWorkflow $workflow, Request $request)
    {
        abort_unless($post->image_path, 404);
        $bytes = $this->run(fn () => $workflow->assertImage($post));

        return response($bytes, 200, ['Content-Type' => 'image/png',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$post->symbol.'-'.$post->session_date.'-gex.png"']);
    }

    public function snapshot(SocialPost $post)
    {
        abort_unless($post->snapshot, 404);

        return response()->json($post->snapshot)->header('Content-Disposition', 'attachment; filename="'.$post->symbol.'-'.$post->session_date.'-source.json"');
    }

    public function verify(XPublisher $x)
    {
        try {
            $x->verifyAccount();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['connection' => 'Connection check failed. Check the four OAuth 1.0a settings, Read and write permission, and X credits.']);
        }

        return back()->with('status', 'Connected to @GexOptions. No post was sent.');
    }

    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['post' => $e->getMessage()]);
        }
    }
}
