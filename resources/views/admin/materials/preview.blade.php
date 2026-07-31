@extends('larena-admin::layouts.app')

@section('title', $title)
@section('heading', $title)
@section('eyebrow', __('larena-content::admin.materials.preview'))
@section('description', __('larena-content::admin.materials.preview_description', ['revision' => $material->currentRevision]))
@section('actions'){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.edit', $material->itemRef->uuid()), __('larena-content::admin.actions.back'))->html !!}@endsection

@section('content')<section class="larena-panel larena-home-panel"><dl>@foreach($values as $key => $value)<dt><strong>{{ $labels[$key] ?? $key }}</strong></dt><dd>@if(is_bool($value)){{ $value ? __('larena-content::admin.boolean.yes') : __('larena-content::admin.boolean.no') }}@else{{ $value }}@endif</dd>@endforeach</dl></section>@endsection
