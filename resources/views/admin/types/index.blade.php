@extends('larena-admin::layouts.app')

@section('title', __('larena-content::admin.types.title'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('description', __('larena-content::admin.types.description'))
@section('actions')@if($canCreate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.types.create'), __('larena-content::admin.actions.create_type'), 'primary', 'default')->html !!}@endif @endsection

@section('content')
@include('larena-content::admin.partials.editorial-steps')
<section class="larena-admin-card">
  <div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>{{ __('larena-content::admin.fields.key') }}</th><th>{{ __('larena-content::admin.fields.label') }}</th><th>{{ __('larena-content::admin.fields.version') }}</th><th>{{ __('larena-content::admin.fields.fields') }}</th></tr></thead><tbody>
  @forelse($types as $type)<tr><td><code>{{ $type['key'] }}</code></td><td>{{ $type['label'] }}</td><td>{{ $type['version'] }}</td><td>{{ $type['fields'] }}</td></tr>@empty<tr><td colspan="4">{{ __('larena-content::admin.types.empty') }}</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
