@extends('larena-admin::layouts.app')
@section('title', __('larena-content::admin.title'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('heading', __('larena-content::admin.title'))
@section('description', __('larena-content::admin.description'))
@section('content')
@include('larena-content::admin.partials.editorial-steps')
@if(session('status')){!! $ui->alert((string) session('status'), 'success') !!}@endif
@if($errors->any()){!! $ui->alert(implode(' ', $errors->all()), 'danger') !!}@endif
@if($historical){!! $ui->alert(__('larena-content::admin.messages.historical', ['revision' => $revision->revision]), 'info') !!}@endif

<div class="larena-role-summary">
  <article><h2>{{ __('larena-content::admin.summary.revision') }}</h2><p>{{ $revision?->revision ?? 0 }}</p></article>
  <article><h2>{{ __('larena-content::admin.summary.status') }}</h2><p>{!! $ui->badge($revision?->status ?? 'empty', $revision?->status === 'published' ? 'success' : 'neutral') !!}</p></article>
  <article><h2>{{ __('larena-content::admin.summary.published') }}</h2><p>{{ $revision?->publishedRevision ?? '—' }}</p></article>
</div>

<section class="larena-panel larena-home-panel">
  <div class="larena-page-heading">
    <div><h2>{{ __('larena-content::admin.navigation.heading') }}</h2><p>{{ __('larena-content::admin.navigation.help') }}</p></div>
    @if($canUpdate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index', ['add_node' => 1]), __('larena-content::admin.actions.add_node'), 'secondary', 'outline')->html !!}@endif
  </div>
  <form method="post" action="{{ route('larena.content.admin.structure.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="expected_revision" value="{{ $revision?->revision ?? 0 }}">
    <div class="larena-table-scroll" tabindex="0"><table class="larena-table larena-table-stack">
      <thead><tr><th>{{ __('larena-content::admin.fields.label') }}</th><th>{{ __('larena-content::admin.fields.parent') }}</th><th>{{ __('larena-content::admin.fields.position') }}</th><th>{{ __('larena-content::admin.fields.target') }}</th><th>{{ __('larena-content::admin.fields.visible') }}</th><th>{{ __('larena-content::admin.fields.remove') }}</th></tr></thead>
      <tbody>
      @forelse($nodes as $index => $node)
        <tr>
          <td class="larena-field" data-label="{{ __('larena-content::admin.fields.label') }}"><input type="hidden" name="nodes[{{ $index }}][node_ref]" value="{{ $node['node_ref'] }}"><input name="nodes[{{ $index }}][label]" value="{{ old('nodes.'.$index.'.label', $node['label']) }}" maxlength="200" required @disabled(!$canUpdate)></td>
          <td class="larena-field" data-label="{{ __('larena-content::admin.fields.parent') }}"><select name="nodes[{{ $index }}][parent_ref]" @disabled(!$canUpdate)><option value="">—</option>@foreach($nodes as $parent)@if($parent['node_ref'] !== $node['node_ref'])<option value="{{ $parent['node_ref'] }}" @selected($node['parent_ref'] === $parent['node_ref'])>{{ $parent['label'] ?: __('larena-content::admin.navigation.unnamed_node') }}</option>@endif @endforeach</select></td>
          <td class="larena-field" data-label="{{ __('larena-content::admin.fields.position') }}"><input type="number" min="0" max="199" name="nodes[{{ $index }}][position]" value="{{ $node['position'] }}" required @disabled(!$canUpdate)></td>
          <td class="larena-field" data-label="{{ __('larena-content::admin.fields.target') }}">
            <select class="sf-input" name="nodes[{{ $index }}][target_type]" @disabled(!$canUpdate)><option value="content" @selected($node['target_type'] === 'content')>{{ __('larena-content::admin.navigation.content_target') }}</option><option value="external" @selected($node['target_type'] === 'external')>{{ __('larena-content::admin.navigation.external_target') }}</option></select>
            <select class="sf-input" name="nodes[{{ $index }}][content_item_ref]" @disabled(!$canUpdate)><option value="">{{ __('larena-content::admin.navigation.choose_page') }}</option>@foreach($contentOptions as $option)<option value="{{ $option['ref'] }}" @selected($node['content_item_ref'] === $option['ref'])>{{ $option['label'] }}{{ $option['published'] ? '' : ' · '.__('larena-content::admin.navigation.draft_badge') }}</option>@endforeach</select>
            <input class="sf-input" type="url" name="nodes[{{ $index }}][external_url]" value="{{ $node['external_url'] }}" placeholder="https://example.com" @disabled(!$canUpdate)>
          </td>
          <td data-label="{{ __('larena-content::admin.fields.visible') }}"><input type="hidden" name="nodes[{{ $index }}][visible]" value="0"><input type="checkbox" name="nodes[{{ $index }}][visible]" value="1" @checked($node['visible']) @disabled(!$canUpdate)></td>
          <td data-label="{{ __('larena-content::admin.fields.remove') }}"><input type="checkbox" name="nodes[{{ $index }}][remove]" value="1" @disabled(!$canUpdate)></td>
        </tr>
      @empty<tr><td colspan="6">{{ __('larena-content::admin.navigation.empty') }}</td></tr>@endforelse
      </tbody>
    </table></div>

    <div class="larena-page-heading">
      <div><h2>{{ __('larena-content::admin.seo.heading') }}</h2><p>{{ __('larena-content::admin.seo.help') }}</p></div>
      @if($canUpdate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index', ['add_seo' => 1]), __('larena-content::admin.actions.add_seo'), 'secondary', 'outline')->html !!}@endif
    </div>
    <div class="larena-table-scroll" tabindex="0"><table class="larena-table larena-table-stack">
      <thead><tr><th>Content item</th><th>Canonical</th><th>SEO title</th><th>Description</th><th>Robots</th><th>{{ __('larena-content::admin.fields.remove') }}</th></tr></thead>
      <tbody>@forelse($seo as $index => $entry)<tr>
        <td class="larena-field" data-label="{{ __('larena-content::admin.fields.content_item') }}"><select name="seo[{{ $index }}][item_ref]" required @disabled(!$canUpdate)><option value="">{{ __('larena-content::admin.navigation.choose_page') }}</option>@foreach($contentOptions as $option)<option value="{{ $option['ref'] }}" @selected($entry['item_ref'] === $option['ref'])>{{ $option['label'] }}</option>@endforeach</select></td>
        <td class="larena-field" data-label="{{ __('larena-content::admin.fields.canonical') }}"><input name="seo[{{ $index }}][canonical_path]" value="{{ $entry['canonical_path'] }}" placeholder="/path" @disabled(!$canUpdate)></td>
        <td class="larena-field" data-label="{{ __('larena-content::admin.fields.seo_title') }}"><input name="seo[{{ $index }}][seo_title]" value="{{ $entry['seo_title'] }}" maxlength="255" @disabled(!$canUpdate)></td>
        <td class="larena-field" data-label="{{ __('larena-content::admin.fields.description') }}"><textarea name="seo[{{ $index }}][description]" maxlength="500" @disabled(!$canUpdate)>{{ $entry['description'] }}</textarea></td>
        <td class="larena-field" data-label="{{ __('larena-content::admin.fields.robots') }}"><select name="seo[{{ $index }}][robots]" @disabled(!$canUpdate)>@foreach(['index,follow','index,nofollow','noindex,follow','noindex,nofollow'] as $policy)<option value="{{ $policy }}" @selected($entry['robots'] === $policy)>{{ $policy }}</option>@endforeach</select></td>
        <td data-label="{{ __('larena-content::admin.fields.remove') }}"><input type="checkbox" name="seo[{{ $index }}][remove]" value="1" @disabled(!$canUpdate)></td>
      </tr>@empty<tr><td colspan="6">{{ __('larena-content::admin.seo.empty') }}</td></tr>@endforelse</tbody>
    </table></div>
    @if($canUpdate)<div class="larena-form-actions">{!! $ui->button('actions.save', 'primary') !!}</div>@endif
  </form>
</section>

@if($revision && !$historical)<section class="larena-panel larena-home-panel"><h2>{{ __('larena-content::admin.workflow.heading') }}</h2><div class="larena-form-actions">
  @if($canSubmit)<form method="post" action="{{ route('larena.content.admin.structure.submit_review') }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revision->revision }}">{!! $ui->button('actions.submit_review', 'secondary') !!}</form>@endif
  @if($canPublish)<form method="post" action="{{ route('larena.content.admin.structure.publish') }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revision->revision }}">{!! $ui->button('actions.publish', 'primary') !!}</form>@endif
</div></section>@endif

@if($canRestore && $revision)<section class="larena-panel larena-home-panel"><form method="post" action="{{ route('larena.content.admin.structure.restore', $revision->revision) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revisions[count($revisions)-1]->revision }}">{!! $ui->button('actions.restore', 'primary') !!}</form></section>@endif

<section class="larena-panel"><h2 class="larena-visually-hidden">{{ __('larena-content::admin.revisions.heading') }}</h2><div class="larena-table-scroll" tabindex="0"><table class="larena-table larena-table-stack"><thead><tr><th>{{ __('larena-content::admin.fields.revision') }}</th><th>{{ __('larena-content::admin.fields.status') }}</th><th>{{ __('larena-content::admin.fields.author') }}</th><th>{{ __('larena-content::admin.fields.created') }}</th></tr></thead><tbody>@forelse(array_reverse($revisions) as $item)<tr><td data-label="{{ __('larena-content::admin.fields.revision') }}"><a href="{{ route('larena.content.admin.structure.revision', $item->revision) }}">#{{ $item->revision }}</a></td><td data-label="{{ __('larena-content::admin.fields.status') }}">{!! $ui->badge($item->status, $item->status === 'published' ? 'success' : 'neutral') !!}</td><td data-label="{{ __('larena-content::admin.fields.author') }}">{{ __('larena-content::admin.revisions.authenticated_operator') }}</td><td data-label="{{ __('larena-content::admin.fields.created') }}">{{ $item->createdAt->format('Y-m-d H:i:s') }}</td></tr>@empty<tr><td colspan="4">{{ __('larena-content::admin.revisions.empty') }}</td></tr>@endforelse</tbody></table></div></section>

<section class="larena-panel larena-home-panel"><h2>{{ __('larena-content::admin.redirects.heading') }}</h2><p>{{ __('larena-content::admin.redirects.help') }}</p>@if($canRedirects)<div class="larena-table-scroll" tabindex="0"><table class="larena-table larena-table-stack"><thead><tr><th>{{ __('larena-content::admin.fields.type') }}</th><th>{{ __('larena-content::admin.fields.locale') }}</th><th>{{ __('larena-content::admin.redirects.from') }}</th><th>{{ __('larena-content::admin.redirects.to') }}</th></tr></thead><tbody>@forelse($redirects as $redirect)<tr><td data-label="{{ __('larena-content::admin.fields.type') }}">{{ $redirect['type_key'] }}</td><td data-label="{{ __('larena-content::admin.fields.locale') }}">{{ $redirect['locale'] }}</td><td data-label="{{ __('larena-content::admin.redirects.from') }}">/pages/{{ $redirect['type_key'] }}/{{ $redirect['source_slug'] }}</td><td data-label="{{ __('larena-content::admin.redirects.to') }}">{{ $contentLabels[$redirect['item_ref']] ?? __('larena-content::admin.redirects.published_page') }}</td></tr>@empty<tr><td colspan="4">{{ __('larena-content::admin.redirects.empty') }}</td></tr>@endforelse</tbody></table></div>@else{!! $ui->alert(__('larena-content::admin.redirects.denied'), 'warning') !!}@endif</section>
@endsection
