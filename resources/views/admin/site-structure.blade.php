@extends('larena-admin::layouts.app')
@section('title', __('larena-content::admin.title'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('heading', __('larena-content::admin.title'))
@section('description', __('larena-content::admin.description'))
@section('content')
@if(session('status')){!! $ui->alert((string) session('status'), 'success') !!}@endif
@if($errors->any()){!! $ui->alert(implode(' ', $errors->all()), 'danger') !!}@endif
@if($historical){!! $ui->alert(__('larena-content::admin.messages.historical', ['revision' => $revision->revision]), 'info') !!}@endif

<div class="larena-admin-summary-grid">
  <article class="larena-admin-summary-card"><span>{{ __('larena-content::admin.summary.revision') }}</span><strong>{{ $revision?->revision ?? 0 }}</strong></article>
  <article class="larena-admin-summary-card"><span>{{ __('larena-content::admin.summary.status') }}</span><strong>{!! $ui->badge($revision?->status ?? 'empty', $revision?->status === 'published' ? 'success' : 'neutral') !!}</strong></article>
  <article class="larena-admin-summary-card"><span>{{ __('larena-content::admin.summary.published') }}</span><strong>{{ $revision?->publishedRevision ?? '—' }}</strong></article>
</div>

<section class="larena-admin-card">
  <div class="larena-admin-section-heading">
    <div><h2>{{ __('larena-content::admin.navigation.heading') }}</h2><p>{{ __('larena-content::admin.navigation.help') }}</p></div>
    @if($canUpdate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index', ['add_node' => 1]), __('larena-content::admin.actions.add_node'), 'secondary', 'outline')->html !!}@endif
  </div>
  <form method="post" action="{{ route('larena.content.admin.structure.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="expected_revision" value="{{ $revision?->revision ?? 0 }}">
    <div class="larena-admin-table-wrap"><table class="larena-admin-table">
      <thead><tr><th>{{ __('larena-content::admin.fields.label') }}</th><th>{{ __('larena-content::admin.fields.parent') }}</th><th>{{ __('larena-content::admin.fields.position') }}</th><th>{{ __('larena-content::admin.fields.target') }}</th><th>{{ __('larena-content::admin.fields.visible') }}</th><th>{{ __('larena-content::admin.fields.remove') }}</th></tr></thead>
      <tbody>
      @forelse($nodes as $index => $node)
        <tr>
          <td><input type="hidden" name="nodes[{{ $index }}][node_ref]" value="{{ $node['node_ref'] }}"><input class="sf-input" name="nodes[{{ $index }}][label]" value="{{ old('nodes.'.$index.'.label', $node['label']) }}" maxlength="200" required @disabled(!$canUpdate)></td>
          <td><select class="sf-input" name="nodes[{{ $index }}][parent_ref]" @disabled(!$canUpdate)><option value="">—</option>@foreach($nodes as $parent)@if($parent['node_ref'] !== $node['node_ref'])<option value="{{ $parent['node_ref'] }}" @selected($node['parent_ref'] === $parent['node_ref'])>{{ $parent['label'] ?: $parent['node_ref'] }}</option>@endif @endforeach</select></td>
          <td><input class="sf-input" type="number" min="0" max="199" name="nodes[{{ $index }}][position]" value="{{ $node['position'] }}" required @disabled(!$canUpdate)></td>
          <td>
            <select class="sf-input" name="nodes[{{ $index }}][target_type]" @disabled(!$canUpdate)><option value="content" @selected($node['target_type'] === 'content')>Content</option><option value="external" @selected($node['target_type'] === 'external')>HTTPS</option></select>
            <input class="sf-input" name="nodes[{{ $index }}][content_item_ref]" value="{{ $node['content_item_ref'] }}" placeholder="content:item:uuid" @disabled(!$canUpdate)>
            <input class="sf-input" type="url" name="nodes[{{ $index }}][external_url]" value="{{ $node['external_url'] }}" placeholder="https://example.com" @disabled(!$canUpdate)>
          </td>
          <td><input type="hidden" name="nodes[{{ $index }}][visible]" value="0"><input type="checkbox" name="nodes[{{ $index }}][visible]" value="1" @checked($node['visible']) @disabled(!$canUpdate)></td>
          <td><input type="checkbox" name="nodes[{{ $index }}][remove]" value="1" @disabled(!$canUpdate)></td>
        </tr>
      @empty<tr><td colspan="6">{{ __('larena-content::admin.navigation.empty') }}</td></tr>@endforelse
      </tbody>
    </table></div>

    <div class="larena-admin-section-heading">
      <div><h2>{{ __('larena-content::admin.seo.heading') }}</h2><p>{{ __('larena-content::admin.seo.help') }}</p></div>
      @if($canUpdate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index', ['add_seo' => 1]), __('larena-content::admin.actions.add_seo'), 'secondary', 'outline')->html !!}@endif
    </div>
    <div class="larena-admin-table-wrap"><table class="larena-admin-table">
      <thead><tr><th>Content item</th><th>Canonical</th><th>SEO title</th><th>Description</th><th>Robots</th><th>{{ __('larena-content::admin.fields.remove') }}</th></tr></thead>
      <tbody>@forelse($seo as $index => $entry)<tr>
        <td><input class="sf-input" name="seo[{{ $index }}][item_ref]" value="{{ $entry['item_ref'] }}" required @disabled(!$canUpdate)></td>
        <td><input class="sf-input" name="seo[{{ $index }}][canonical_path]" value="{{ $entry['canonical_path'] }}" placeholder="/path" @disabled(!$canUpdate)></td>
        <td><input class="sf-input" name="seo[{{ $index }}][seo_title]" value="{{ $entry['seo_title'] }}" maxlength="255" @disabled(!$canUpdate)></td>
        <td><textarea class="sf-input" name="seo[{{ $index }}][description]" maxlength="500" @disabled(!$canUpdate)>{{ $entry['description'] }}</textarea></td>
        <td><select class="sf-input" name="seo[{{ $index }}][robots]" @disabled(!$canUpdate)>@foreach(['index,follow','index,nofollow','noindex,follow','noindex,nofollow'] as $policy)<option value="{{ $policy }}" @selected($entry['robots'] === $policy)>{{ $policy }}</option>@endforeach</select></td>
        <td><input type="checkbox" name="seo[{{ $index }}][remove]" value="1" @disabled(!$canUpdate)></td>
      </tr>@empty<tr><td colspan="6">{{ __('larena-content::admin.seo.empty') }}</td></tr>@endforelse</tbody>
    </table></div>
    @if($canUpdate)<div class="larena-admin-form-actions">{!! $ui->button('actions.save', 'primary') !!}</div>@endif
  </form>
</section>

@if($revision && !$historical)<section class="larena-admin-card"><h2>{{ __('larena-content::admin.workflow.heading') }}</h2><div class="larena-admin-form-actions">
  @if($canSubmit)<form method="post" action="{{ route('larena.content.admin.structure.submit_review') }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revision->revision }}">{!! $ui->button('actions.submit_review', 'secondary') !!}</form>@endif
  @if($canPublish)<form method="post" action="{{ route('larena.content.admin.structure.publish') }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revision->revision }}">{!! $ui->button('actions.publish', 'primary') !!}</form>@endif
</div></section>@endif

@if($canRestore && $revision)<section class="larena-admin-card"><form method="post" action="{{ route('larena.content.admin.structure.restore', $revision->revision) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $revisions[count($revisions)-1]->revision }}">{!! $ui->button('actions.restore', 'primary') !!}</form></section>@endif

<section class="larena-admin-card"><h2>{{ __('larena-content::admin.revisions.heading') }}</h2><div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>Revision</th><th>Status</th><th>Author</th><th>Created</th></tr></thead><tbody>@forelse(array_reverse($revisions) as $item)<tr><td><a href="{{ route('larena.content.admin.structure.revision', $item->revision) }}">#{{ $item->revision }}</a></td><td>{!! $ui->badge($item->status, $item->status === 'published' ? 'success' : 'neutral') !!}</td><td>{{ $item->createdBy }}</td><td>{{ $item->createdAt->format('Y-m-d H:i:s') }}</td></tr>@empty<tr><td colspan="4">{{ __('larena-content::admin.revisions.empty') }}</td></tr>@endforelse</tbody></table></div></section>

<section class="larena-admin-card"><h2>{{ __('larena-content::admin.redirects.heading') }}</h2><p>{{ __('larena-content::admin.redirects.help') }}</p>@if($canRedirects)<div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>Type</th><th>Locale</th><th>From</th><th>Content item</th></tr></thead><tbody>@forelse($redirects as $redirect)<tr><td>{{ $redirect['type_key'] }}</td><td>{{ $redirect['locale'] }}</td><td>/content/{{ $redirect['type_key'] }}/{{ $redirect['source_slug'] }}</td><td>{{ $redirect['item_ref'] }}</td></tr>@empty<tr><td colspan="4">{{ __('larena-content::admin.redirects.empty') }}</td></tr>@endforelse</tbody></table></div>@else{!! $ui->alert(__('larena-content::admin.redirects.denied'), 'warning') !!}@endif</section>
@endsection
