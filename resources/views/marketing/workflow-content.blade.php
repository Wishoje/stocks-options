<article>
    <header class="mk-hero mk-stack"><p class="mk-eyebrow">{{ $content['eyebrow'] }}</p><h1 class="mk-title">{{ $content['heading'] }}</h1><p class="mk-lede">{{ $content['description'] }}</p><p class="mk-meta">{{ $content['note'] }}</p><a class="mk-button mk-button--primary" href="/pricing">View plans and trial details</a></header>
    <nav aria-label="On this page"><ul>@foreach ($content['sections'] as $section)<li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>@endforeach</ul></nav>
    @foreach ($content['sections'] as $section)
        <section id="{{ $section['id'] }}" class="mk-section mk-guide-prose"><h2>{{ $section['title'] }}</h2>@foreach ($section['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
        @if (isset($section['steps']))<ol>@foreach ($section['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>@endif
        @if (isset($section['rows']))<div class="mk-guide-table"><table><thead><tr>@foreach ($section['rows'][0] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead><tbody>@foreach (array_slice($section['rows'], 1) as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table></div>@endif</section>
    @endforeach
    @if ($content['faqs'])<section class="mk-section"><h2 class="mk-heading">Questions before you start</h2>@foreach ($content['faqs'] as $faq)<details class="mk-faq"><summary>{{ $faq['q'] }}</summary><p>{{ $faq['a'] }}</p></details>@endforeach</section>@endif
    <section class="mk-section mk-stack"><h2 class="mk-heading">Practical guides</h2>@foreach ($content['guides'] as $slug)@php($guide = \App\Support\TradingGuides::find($slug))<a href="/guides/{{ $slug }}">{{ $guide['title'] }}</a>@endforeach</section>
    <p><a href="/about">About and methodology</a> · <a href="/contact">Contact the team</a></p><a class="mk-button mk-button--primary" href="/pricing">Try the workflow on your own watchlist</a>
</article>
