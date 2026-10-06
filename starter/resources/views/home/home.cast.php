<?php
__includes('layouts.header', $data);
__includes('home.partials.' . $data['pageName'], $data);
__includes('layouts.footer', $data);
