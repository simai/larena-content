<?php

return [
    'title' => 'Site structure', 'eyebrow' => 'Content',
    'description' => 'Published navigation, page metadata and managed redirects.',
    'navigation' => ['site_structure' => 'Site structure', 'heading' => 'Navigation tree', 'help' => 'Order and nesting are saved as a versioned Content revision.', 'empty' => 'No navigation nodes yet.'],
    'seo' => ['heading' => 'SEO metadata', 'help' => 'Metadata is published together with this structure revision.', 'empty' => 'No SEO metadata yet.'],
    'workflow' => ['heading' => 'Editorial workflow'],
    'revisions' => ['heading' => 'Revision history', 'empty' => 'No revisions yet.'],
    'redirects' => ['heading' => 'Managed redirects', 'help' => 'Redirects are created by Content publication and cannot be forged manually.', 'empty' => 'No historical locators yet.', 'denied' => 'Your role cannot inspect redirects.'],
    'summary' => ['revision' => 'Current revision', 'status' => 'Status', 'published' => 'Published revision'],
    'fields' => ['label' => 'Label', 'parent' => 'Parent', 'position' => 'Order', 'target' => 'Target', 'visible' => 'Visible', 'remove' => 'Remove'],
    'actions' => ['add_node' => 'Add navigation node', 'add_seo' => 'Add SEO entry', 'save' => 'Save draft', 'submit_review' => 'Submit for review', 'publish' => 'Publish', 'restore' => 'Restore this revision'],
    'messages' => ['saved' => 'Draft revision saved.', 'submitted' => 'Revision submitted for review.', 'published' => 'Revision published.', 'restored' => 'Previous revision restored as a new draft.', 'stale' => 'The structure changed in another request. Reload and try again.', 'rejected' => 'The operation failed closed: :reason.', 'historical' => 'Viewing historical revision #:revision.'],
];
