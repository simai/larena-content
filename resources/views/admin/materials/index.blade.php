@extends('larena-admin::layouts.app')

@section('title', __('larena-content::admin.materials.title'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('description', __('larena-content::admin.materials.description'))
@section('actions')@if($canCreate){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.create', ['type_key' => $selectedType]), __('larena-content::admin.actions.create_material'), 'primary', 'default')->html !!}@endif @endsection

@section('content')
<section class="larena-admin-card"><form method="get" action="{{ route('larena.content.admin.materials.index') }}" class="larena-admin-form"><label>{{ __('larena-content::admin.fields.type') }}<select name="type_key"><option value="">{{ __('larena-content::admin.materials.all_types') }}</option>@foreach($types as $type)<option value="{{ $type['key'] }}" @selected($selectedType === $type['key'])>{{ $type['label'] }}</option>@endforeach</select></label><div class="larena-admin-form-actions">{!! $ui->button('actions.filter') !!}</div></form></section>
<section class="larena-admin-card"><div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>{{ __('larena-content::admin.fields.title') }}</th><th>{{ __('larena-content::admin.fields.type') }}</th><th>{{ __('larena-content::admin.fields.status') }}</th><th>{{ __('larena-content::admin.fields.revision') }}</th><th>{{ __('larena-content::admin.fields.actions') }}</th></tr></thead><tbody>
@forelse($materials as $row)<tr><td><a href="{{ route('larena.content.admin.materials.edit', $row['item']->itemRef->uuid()) }}">{{ $row['title'] }}</a></td><td><code>{{ $row['item']->typeKey->value }}</code></td><td>{!! $ui->badge($row['item']->currentStatus->value, $row['item']->currentStatus->value === 'published' ? 'success' : 'neutral') !!}</td><td>#{{ $row['item']->currentRevision }}</td><td>@if($row['public_url'])<a href="{{ $row['public_url'] }}" target="_blank" rel="noopener">{{ __('larena-content::admin.actions.open_public') }}</a>@endif</td></tr>@empty<tr><td colspan="5">{{ __('larena-content::admin.materials.empty') }}</td></tr>@endforelse
</tbody></table></div></section>
@endsection
