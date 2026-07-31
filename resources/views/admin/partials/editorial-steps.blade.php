<nav class="larena-panel larena-home-panel" aria-label="{{ __('larena-content::admin.workspace.journey_label') }}">
    <div class="larena-page-actions">
        {!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.workspace'), __('larena-content::admin.workspace.steps.overview'), 'secondary', 'outline')->html !!}
        {!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.types.index'), __('larena-content::admin.workspace.steps.model'), 'secondary', 'outline')->html !!}
        @if(\Illuminate\Support\Facades\Route::has('larena.file_manager.admin.files.index'))
            {!! \Larena\Ui\SfActionLink::render(route('larena.file_manager.admin.files.index'), __('larena-content::admin.workspace.steps.media'), 'secondary', 'outline')->html !!}
        @endif
        {!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.materials.index'), __('larena-content::admin.workspace.steps.pages'), 'secondary', 'outline')->html !!}
        {!! \Larena\Ui\SfActionLink::render(route('larena.content.admin.structure.index'), __('larena-content::admin.workspace.steps.navigation'), 'secondary', 'outline')->html !!}
        @if(\Illuminate\Support\Facades\Route::has('larena.search.public'))
            {!! \Larena\Ui\SfActionLink::render(route('larena.search.public'), __('larena-content::admin.workspace.steps.search'), 'secondary', 'outline')->html !!}
        @endif
    </div>
</nav>
