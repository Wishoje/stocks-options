@if ($content['kind'] === 'pricing')
    @php($offer = data_get($page, 'props.offer', []))
    <article class="mk-hero mk-stack">
        <p class="mk-eyebrow">Simple pricing</p><h1 class="mk-title">{{ $content['heading'] }}</h1><p class="mk-lede">{{ $content['description'] }}</p>
        <section class="mk-card mk-card-pad mk-stack"><h2 class="mk-heading">{{ $offer['label'] ?? 'Early Bird' }}</h2><p>{{ $offer['trial_days'] ?? 7 }}-day trial. The trial begins after checkout.</p>
        @foreach (['monthly' => 'month', 'yearly' => 'year'] as $interval => $unit)
            @php($display = data_get($offer, 'display.'.$interval))
            @if (is_array($display) && isset($display['amount_minor']))
                <p>{{ data_get($offer, 'display.currency') }} {{ number_format($display['amount_minor'] / 100, 2) }} per {{ $unit }} after your trial.</p>
                <a class="mk-button mk-button--secondary" href="/register?plan=earlybird&amp;billing={{ $interval }}">Create account for {{ $interval }} billing</a>
            @endif
        @endforeach
        <p>Your selected plan renews automatically unless canceled. Stripe shows the final total and applicable tax before you confirm. Manage or cancel from your account billing portal.</p>
        <h3 class="mk-subheading">Included in both billing choices</h3><ul>@foreach ($content['features'] as $feature)<li>{{ $feature }}</li>@endforeach</ul></section>
        <section><h2 class="mk-heading">Before you start</h2>@foreach ($content['faqs'] as $faq)<details class="mk-faq"><summary>{{ $faq['q'] }}</summary><p>{{ $faq['a'] }}</p></details>@endforeach</section>
    </article>
@elseif ($content['kind'] === 'contact')
    <article class="mk-hero mk-stack"><p class="mk-eyebrow">Support</p><h1 class="mk-title">Tell us what you need help with.</h1>
        <p class="mk-lede">Contact GEX Options about product access, billing or market-data questions.</p><h2 class="mk-heading">Email the team</h2><a href="mailto:support@gexoptions.com">support@gexoptions.com</a>
        <p>Include your account email, the page, symbol and timeframe involved, and any visible error with the time it occurred. Please do not send passwords, payment details or API keys.</p>
        <p>The support form is available with JavaScript enabled. You can also email the team directly.</p><a href="/about">About GEX Options and our methodology</a>
    </article>
@else
    @php($legal = data_get($page, 'props.terms') ?? data_get($page, 'props.policy'))
    <article class="mk-hero mk-guide-prose">{!! $legal !!}<p>Questions about this document? <a href="mailto:support@gexoptions.com">Contact support.</a></p></article>
@endif
