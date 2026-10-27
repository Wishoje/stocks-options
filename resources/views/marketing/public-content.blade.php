{{-- Visible initial HTML for every visitor. Vue replaces this inside the Inertia root when it mounts. --}}
<div class="marketing-site">
    <header class="mk-header">
        <nav class="mk-container mk-header-row" aria-label="Primary navigation">
            <a class="mk-brand" href="/">GEX Options</a>
            <div class="mk-nav"><a class="mk-nav-link" href="/features">Features</a><a class="mk-nav-link" href="/spy-gex-levels">SPY levels</a><a class="mk-nav-link" href="/guides">Guides</a><a class="mk-nav-link" href="/pricing">Pricing</a><a class="mk-nav-link" href="/contact">Contact</a></div>
        </nav>
    </header>
    <main id="marketing-main" class="mk-container">
        @if (($content['kind'] ?? null) === 'spy')
            @include('marketing.spy-content', ['preview' => data_get($page, 'props.preview')])
        @elseif (($content['kind'] ?? null) === 'workflow')
            @include('marketing.workflow-content', ['content' => $content])
        @elseif (in_array($content['kind'] ?? null, ['pricing', 'contact', 'legal'], true))
            @include('marketing.support-content', ['content' => $content])
        @elseif (isset($content['kind']))
            @include('marketing.guides-content', ['content' => $content])
        @else
        <article>
            <header class="mk-hero mk-stack">
                <p class="mk-eyebrow">{{ $content['intro']['eyebrow'] }}</p>
                <h1 class="mk-title">{{ $content['intro']['heading'] }}@if (isset($content['intro']['accent']))<br><span class="mk-accent">{{ $content['intro']['accent'] }}</span>@endif</h1>
                <p class="mk-lede">{{ $content['intro']['description'] }}</p>
                <a class="mk-button mk-button--primary" href="/pricing">Explore plans and the free trial</a>
            </header>

            @if (isset($content['workflows']))
                <section id="how-it-works" class="mk-section mk-stack">
                    <h2 class="mk-heading">Intraday or swing trading. A workflow for both.</h2>
                    @foreach ($content['workflows'] as $workflow)
                        <section id="{{ $workflow['id'] }}" class="mk-card mk-card-pad">
                            <p class="mk-eyebrow">{{ $workflow['label'] }}</p><h3 class="mk-subheading">{{ $workflow['title'] }}</h3>
                            <p class="mk-copy">{{ $workflow['description'] }}</p>
                            <ol>@foreach ($workflow['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                            <p class="mk-copy">{{ $workflow['note'] }}</p><a href="{{ $workflow['href'] }}">{{ $workflow['link'] }}</a>
                        </section>
                    @endforeach
                </section>
                @foreach ($content['stories'] as $story)
                    <section id="{{ $story['id'] }}" class="mk-section mk-stack">
                        <h2 class="mk-heading">{{ $story['title'] }}</h2><p class="mk-copy">{{ $story['description'] }}</p>
                        <ul>@foreach ($story['bullets'] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul>
                        <a href="{{ $story['href'] }}">{{ $story['link'] }}</a>
                    </section>
                @endforeach
                <section class="mk-section mk-stack">
                    <h2 class="mk-heading">The tools behind the plan, included.</h2>
                    @foreach ($content['tools'] as $tool)
                        <section><h3 class="mk-subheading"><a href="{{ $tool['href'] }}">{{ $tool['title'] }}</a></h3><p class="mk-copy">{{ $tool['description'] }}</p></section>
                    @endforeach
                    <a href="/features">Explore the complete feature set</a>
                </section>
            @endif

            @if (isset($content['groups']))
                <nav aria-label="All product features"><ul>
                    @foreach ($content['groups'] as $group)
                        @foreach ($group['features'] as $feature)<li><a href="#{{ $feature['id'] }}">{{ $feature['title'] }}</a></li>@endforeach
                    @endforeach
                </ul></nav>
                @foreach ($content['groups'] as $group)
                    <section id="{{ $group['id'] }}" class="mk-section mk-stack">
                        <h2 class="mk-heading">{{ $group['title'] }}</h2><p class="mk-lede">{{ $group['description'] }}</p>
                        @foreach ($group['features'] as $feature)
                            <section id="{{ $feature['id'] }}" class="mk-card mk-card-pad">
                                <h3 class="mk-subheading">{{ $feature['title'] }}</h3><p class="mk-copy">{{ $feature['description'] }}</p>
                                <ul>@foreach ($feature['bullets'] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul>@if (isset($feature['learnHref']))<a href="{{ $feature['learnHref'] }}">See how this workflow works</a>@endif
                            </section>
                        @endforeach
                    </section>
                @endforeach
            @endif

            <section class="mk-section mk-stack">
                <h2 class="mk-heading">Learn how to use the tools</h2>
                @foreach (\App\Support\TradingGuides::all() as $guide)
                    <h3><a href="/guides/{{ $guide['slug'] }}">{{ $guide['title'] }}</a></h3><p>{{ $guide['description'] }}</p>
                @endforeach
            </section>
            <section class="mk-section mk-stack">
                <h2 class="mk-heading">Questions about GEX Options</h2>
                @foreach ($content['faqs'] as $faq)<details class="mk-faq"><summary>{{ $faq['q'] }}</summary><p>{{ $faq['a'] }}</p></details>@endforeach
                <a class="mk-button mk-button--primary" href="/pricing">View pricing and trial details</a>
            </section>
        </article>
        @endif
    </main>
    <footer class="mk-footer">
        <div class="mk-container mk-footer-grid">
            <div class="mk-footer-brand"><a href="/" class="mk-brand mk-brand--footer" aria-label="GEX Options home"><img src="/marketing/gexoptions_logo.svg" alt="GEX Options" class="mk-brand__logo" width="798" height="316" loading="lazy"></a><p class="mk-meta">Options analytics for market levels, positioning, and activity.</p></div>
            <nav class="mk-footer-links" aria-label="Footer navigation">
                @foreach (json_decode(file_get_contents(resource_path('marketing/footer-links.json')), true, flags: JSON_THROW_ON_ERROR) as $group)
                    <div class="mk-footer-group"><h2 class="mk-footer-heading">{{ $group['title'] }}</h2><ul>@foreach ($group['links'] as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach</ul></div>
                @endforeach
            </nav>
        </div>
        <div class="mk-container mk-footer-bottom"><p class="mk-meta">&copy; {{ date('Y') }} GEX Options.</p><a href="mailto:support@gexoptions.com">support@gexoptions.com</a></div>
    </footer>
</div>
