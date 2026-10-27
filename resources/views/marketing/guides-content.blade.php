@if ($content['kind'] === 'guides')
    <header class="mk-hero mk-stack">
        <p class="mk-eyebrow">Trading guides</p><h1 class="mk-title">Learn what to look for. Build a clearer plan.</h1>
        <p class="mk-lede">Practical walkthroughs of GEX levels, intraday alerts and multi-day swing plans.</p>
    </header>
    <section class="mk-section mk-grid-2">
        @foreach ($content['guides'] as $guide)
            <article class="mk-card mk-card-pad mk-stack"><p class="mk-eyebrow">{{ $guide['category'] }}</p><h2 class="mk-subheading"><a href="/guides/{{ $guide['slug'] }}">{{ $guide['title'] }}</a></h2><p class="mk-copy">{{ $guide['description'] }}</p><p class="mk-meta">{{ $guide['readTime'] }} · GEX Options</p></article>
        @endforeach
    </section>
@else
    @php($guide = $content['guide'])
    <article>
        <header class="mk-hero mk-stack"><a href="/guides">All trading guides</a><p class="mk-eyebrow">{{ $guide['category'] }}</p><h1 class="mk-title">{{ $guide['title'] }}</h1><p class="mk-lede">{{ $guide['description'] }}</p><p class="mk-meta">By <a href="/about">GEX Options</a> · Published <time datetime="{{ $guide['published'] }}">{{ $guide['published'] }}</time>@if ($guide['updated'] !== $guide['published']) · Updated <time datetime="{{ $guide['updated'] }}">{{ $guide['updated'] }}</time>@endif · {{ $guide['readTime'] }}</p><p class="mk-copy">{{ $guide['takeaway'] }}</p></header>
        <nav aria-label="In this guide"><ul>@foreach ($guide['sections'] as $section)<li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>@endforeach</ul></nav>
        @foreach ($guide['sections'] as $section)
            <section id="{{ $section['id'] }}" class="mk-section mk-guide-prose"><h2>{{ $section['title'] }}</h2>
                @foreach ($section['paragraphs'] ?? [] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                @if (isset($section['steps']))<ol>@foreach ($section['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>@endif
                @if (isset($section['rows']))<div class="mk-guide-table"><table><thead><tr>@foreach ($section['rows'][0] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead><tbody>@foreach (array_slice($section['rows'], 1) as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table></div>@endif
            </section>
        @endforeach
        <p><a href="/about">Read our methodology and editorial information.</a></p><section class="mk-section mk-guide-prose"><h2>Your quick checklist</h2><ul>@foreach ($guide['checklist'] as $item)<li>{{ $item }}</li>@endforeach</ul><a href="{{ $guide['featureHref'] }}">{{ $guide['featureLabel'] }}</a></section>
        @if ($guide['sources'])<section class="mk-guide-prose"><h2>Further reading</h2><ul>@foreach ($guide['sources'] as $source)<li><a href="{{ $source['url'] }}">{{ $source['label'] }}</a></li>@endforeach</ul></section>@endif
        <section class="mk-section mk-stack"><h2 class="mk-heading">Try the workflow on the symbols you follow.</h2><a class="mk-button mk-button--primary" href="/pricing">View plans and trial details</a><a href="/guides">Read another trading guide</a></section>
    </article>
@endif
