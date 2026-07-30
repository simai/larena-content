@extends('larena-admin::layouts.app')

@section('title', __('larena-content::admin.workspace.title').' · Larena')
@section('eyebrow', __('larena-content::admin.eyebrow'))
@section('heading', __('larena-content::admin.workspace.title'))
@section('description', __('larena-content::admin.workspace.description'))

@section('content')
@include('larena-content::admin.partials.editorial-steps')

@if($fileIntegrationFailed)
    <div data-larena-state="system-error" role="alert">{!! $ui->alert(__('larena-content::admin.materials.filesystem_unavailable'), 'danger') !!}</div>
@endif

<div class="larena-admin-summary-grid" aria-label="{{ __('larena-content::admin.workspace.summary_label') }}">
    @foreach(['types', 'files', 'materials', 'published', 'navigation'] as $key)
        <article class="larena-admin-summary-card">
            <span>{{ __('larena-content::admin.workspace.counts.'.$key) }}</span>
            <strong>{{ $counts[$key] }}</strong>
        </article>
    @endforeach
</div>

<section class="larena-admin-card">
    <h2>{{ __('larena-content::admin.workspace.next_title') }}</h2>
    <ol>
        <li>
            <strong>{{ __('larena-content::admin.workspace.steps.model') }}</strong>
            <p>{{ __('larena-content::admin.workspace.help.model') }}</p>
            @if($canCreateType){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.types.create'), __('larena-content::admin.actions.create_type'), 'primary')->html !!}@endif
        </li>
        <li>
            <strong>{{ __('larena-content::admin.workspace.steps.media') }}</strong>
            <p>{{ __('larena-content::admin.workspace.help.media') }}</p>
            @if(\Illuminate\Support\Facades\Route::has('larena.file_manager.admin.files.create')){!! \Larena\Ui\SfActionLink::render(route('larena.file_manager.admin.files.create'), __('larena-content::admin.workspace.actions.upload'), 'primary')->html !!}@endif
        </li>
        <li>
            <strong>{{ __('larena-content::admin.workspace.steps.pages') }}</strong>
            <p>{{ __('larena-content::admin.workspace.help.pages') }}</p>
            @if($canCreateMaterial){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.create'), __('larena-content::admin.actions.create_material'), 'primary')->html !!}@endif
        </li>
        <li>
            <strong>{{ __('larena-content::admin.workspace.steps.navigation') }}</strong>
            <p>{{ __('larena-content::admin.workspace.help.navigation') }}</p>
            @if($canEditStructure){!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index'), __('larena-content::admin.workspace.actions.open_structure'), 'primary')->html !!}@endif
        </li>
    </ol>
</section>
@endsection
