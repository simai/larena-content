@extends('larena-admin::layouts.app')

@section('title', $material ? __('larena-content::admin.materials.edit') : __('larena-content::admin.materials.create'))
@section('heading', $material ? __('larena-content::admin.materials.edit') : __('larena-content::admin.materials.create'))
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('description', __('larena-content::admin.materials.form_description'))
@section('actions'){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.index'), __('larena-content::admin.actions.back'))->html !!} @if($material){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.preview', $material->itemRef->uuid()), __('larena-content::admin.actions.preview'), 'secondary', 'outline')->html !!}@endif @endsection

@section('content')
@include('larena-content::admin.partials.editorial-steps')
@if($errors->has('material')){!! $ui->alert($errors->first('material'), 'danger') !!}@endif
@if($fileIntegrationFailed)<div data-larena-state="system-error" role="alert">{!! $ui->alert(__('larena-content::admin.materials.filesystem_unavailable'), 'danger') !!}</div>@endif
@if(!$type)
<section class="larena-admin-card"><h2>{{ __('larena-content::admin.materials.choose_type') }}</h2>@if($types === []){!! $ui->alert(__('larena-content::admin.materials.no_types'), 'warning') !!}@else<form method="get" action="{{ route('larena.content.admin.materials.create') }}" class="larena-admin-form"><label>{{ __('larena-content::admin.fields.type') }}<select name="type_key" required>@foreach($types as $option)<option value="{{ $option['key'] }}">{{ $option['label'] }}</option>@endforeach</select></label><div class="larena-admin-form-actions">{!! $ui->button('actions.continue', 'primary') !!}</div></form>@endif</section>
@else
<form method="post" action="{{ $material ? route('larena.content.admin.materials.update', $material->itemRef->uuid()) : route('larena.content.admin.materials.store') }}" class="larena-admin-form">@csrf @if($material)@method('put')<input type="hidden" name="expected_revision" value="{{ $material->currentRevision }}">@else<input type="hidden" name="type_key" value="{{ $type->typeKey->value }}">@endif
<section class="larena-admin-card"><div class="larena-admin-form-grid">
@if(!$material)<label>{{ __('larena-content::admin.fields.locale') }}<input name="locale" value="{{ old('locale', 'en') }}" required></label>@endif
<label>{{ __('larena-content::admin.fields.slug') }}<input name="slug" value="{{ old('slug', $material?->currentSlug->value) }}" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" aria-describedby="larena-slug-help" @disabled($material && !$canUpdate)><small id="larena-slug-help">{{ __('larena-content::admin.materials.slug_help') }}</small></label>
<label>{{ __('larena-content::admin.fields.visibility') }}<select name="visibility" @disabled($material && !$canUpdate)><option value="public" @selected(old('visibility', $material?->currentVisibility->value ?? 'public') === 'public')>{{ __('larena-content::admin.visibility.public') }}</option><option value="private" @selected(old('visibility', $material?->currentVisibility->value ?? 'public') === 'private')>{{ __('larena-content::admin.visibility.private') }}</option></select></label>
</div></section>
@if($material && !$canUpdate){!! $ui->alert(__('larena-content::admin.materials.read_only'), 'info') !!}@endif
<section class="larena-admin-card"><h2>{{ __('larena-content::admin.materials.fields_heading') }}</h2><div class="larena-admin-form-grid">
@foreach($type->fieldDefinitions as $field)
<label>{{ $ui->fieldLabel($field->key) }} <small>{{ __('larena-content::admin.field_types.'.$field->propertyType) }}</small>
@if($field->propertyType === 'text')<textarea name="values[{{ $field->key }}]" @required($field->required) @disabled($material && !$canUpdate)>{{ old('values.'.$field->key, $values[$field->key] ?? '') }}</textarea>
@elseif($field->propertyType === 'boolean')<input type="hidden" name="values[{{ $field->key }}]" value="0"><input type="checkbox" name="values[{{ $field->key }}]" value="1" @checked(old('values.'.$field->key, $values[$field->key] ?? false)) @disabled($material && !$canUpdate)>
@elseif($field->propertyType === 'file')<select name="values[{{ $field->key }}]" @required($field->required) @disabled($material && !$canUpdate)><option value="">{{ __('larena-content::admin.materials.no_file') }}</option>@foreach($files as $file)<option value="{{ $file['ref'] }}" @selected(old('values.'.$field->key, $values[$field->key] ?? '') === $file['ref'])>{{ $file['label'] }}</option>@endforeach</select>
@elseif($field->propertyType === 'relation')<select name="values[{{ $field->key }}]" @required($field->required) @disabled($material && !$canUpdate)><option value="">{{ __('larena-content::admin.materials.no_relation') }}</option>@foreach($relations as $relation)<option value="{{ $relation['ref'] }}" @selected(old('values.'.$field->key, $values[$field->key] ?? '') === $relation['ref'])>{{ $relation['label'] }}</option>@endforeach</select>
@else<input type="{{ $field->propertyType === 'date' ? 'date' : ($field->propertyType === 'number' ? 'number' : 'text') }}" @if($field->propertyType === 'number')step="any"@endif name="values[{{ $field->key }}]" value="{{ old('values.'.$field->key, $values[$field->key] ?? '') }}" @required($field->required) @disabled($material && !$canUpdate)>@endif
</label>@endforeach
</div></section>
@if(!$material || $canUpdate)<div class="larena-admin-form-actions">{!! $ui->button($material ? 'actions.save_material' : 'actions.create_material', 'primary') !!}</div>@endif</form>

@if($material)<section class="larena-admin-card"><h2>{{ __('larena-content::admin.workflow.heading') }}</h2><p>{{ __('larena-content::admin.materials.current_state', ['status' => $material->currentStatus->value, 'revision' => $material->currentRevision]) }}</p><div class="larena-admin-form-actions">
@if($canSubmit)<form method="post" action="{{ route('larena.content.admin.materials.submit', $material->itemRef->uuid()) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $material->currentRevision }}">{!! $ui->button('actions.submit_review') !!}</form>@endif
@if($canPublish)<form method="post" action="{{ route('larena.content.admin.materials.publish', $material->itemRef->uuid()) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $material->currentRevision }}">{!! $ui->button('actions.publish', 'primary') !!}</form>@endif
@if($canUnpublish && $material->publishedRevision)<form method="post" action="{{ route('larena.content.admin.materials.unpublish', $material->itemRef->uuid()) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $material->currentRevision }}">{!! $ui->button('actions.unpublish', 'danger') !!}</form>@endif
</div></section>
<section class="larena-admin-card"><h2>{{ __('larena-content::admin.revisions.heading') }}</h2><div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>{{ __('larena-content::admin.fields.revision') }}</th><th>{{ __('larena-content::admin.fields.status') }}</th><th>{{ __('larena-content::admin.fields.author') }}</th><th>{{ __('larena-content::admin.fields.actions') }}</th></tr></thead><tbody>@foreach(array_reverse($revisions) as $revision)<tr><td>#{{ $revision->revision }}</td><td>{{ $revision->status->value }}</td><td>{{ $revision->createdBy }}</td><td>@if($canRestore && $revision->revision !== $material->currentRevision)<form method="post" action="{{ route('larena.content.admin.materials.restore', [$material->itemRef->uuid(), $revision->revision]) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $material->currentRevision }}">{!! $ui->button('actions.restore') !!}</form>@endif</td></tr>@endforeach</tbody></table></div></section>@endif
@endif
@endsection
