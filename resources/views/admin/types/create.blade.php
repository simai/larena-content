@extends('larena-admin::layouts.app')

@section('title', __('larena-content::admin.types.create'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('description', __('larena-content::admin.types.create_description'))
@section('actions'){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.types.index'), __('larena-content::admin.actions.back'))->html !!}@endsection

@section('content')
<form method="post" action="{{ route('larena.content.admin.types.store') }}" class="larena-admin-form">@csrf
<section class="larena-admin-card"><div class="larena-admin-form-grid">
  <label>{{ __('larena-content::admin.fields.type_key') }}<input name="type_key" value="{{ old('type_key') }}" required pattern="[a-z][a-z0-9_.]{0,63}"></label>
  <label>{{ __('larena-content::admin.fields.label') }}<input name="label" value="{{ old('label') }}" required maxlength="191"></label>
  <label>{{ __('larena-content::admin.fields.plural_label') }}<input name="plural_label" value="{{ old('plural_label') }}" maxlength="191"></label>
  <label>{{ __('larena-content::admin.fields.description') }}<textarea name="description" maxlength="500">{{ old('description') }}</textarea></label>
</div></section>
<section class="larena-admin-card"><h2>{{ __('larena-content::admin.types.fields_heading') }}</h2><p>{{ __('larena-content::admin.types.fields_help') }}</p>
<div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>{{ __('larena-content::admin.fields.key') }}</th><th>{{ __('larena-content::admin.fields.type') }}</th><th>{{ __('larena-content::admin.fields.required') }}</th></tr></thead><tbody>
@foreach($defaults as $index => $field)<tr><td><input name="fields[{{ $index }}][key]" value="{{ old('fields.'.$index.'.key', $field['key']) }}" required pattern="[a-z][a-z0-9_]{0,63}"></td><td><select name="fields[{{ $index }}][type]" required>@foreach($fieldTypes as $type)<option value="{{ $type }}" @selected(old('fields.'.$index.'.type', $field['type']) === $type)>{{ __('larena-content::admin.field_types.'.$type) }}</option>@endforeach</select></td><td><input type="hidden" name="fields[{{ $index }}][required]" value="0"><input type="checkbox" name="fields[{{ $index }}][required]" value="1" @checked(old('fields.'.$index.'.required', $field['required']))></td></tr>@endforeach
</tbody></table></div></section>
<div class="larena-admin-form-actions">{!! $ui->button('actions.create_type', 'primary') !!}</div></form>
@endsection
