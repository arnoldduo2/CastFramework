<?php
/**
 * A page view: the layout's top, the page's own content, the layout's bottom. This is the file a controller renders
 * ($this->view('home.home', $data)). To make a new page, copy this file and the folder's partials/ file, and change the names.
 * $data['pageName'] picks resources/views/home/partials/<pageName>.cast.php, so one view can show several pages (auth shows login and register).
 */
__includes('layouts.header', $data);
__includes('home.partials.' . $data['pageName'], $data);
__includes('layouts.footer', $data);
