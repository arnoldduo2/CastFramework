@php
/**
 * Root Application Layout (app.cast.php)
 * Serves the default layout to all page views, wrapping them with the global header and footer.
 * Page views render inside this layout without needing to repeat layout includes.
 * Override per view: $this->view('name', ['layout' => 'layouts.custom']) or ['layout' => false]
 */
__includes('layouts.header', $data);
@endphp
@{ $slot ?? $content ?? '' }
@php
__includes('layouts.footer', $data);
@endphp
