<?php
__includes('layouts.header', $data);
__includes('auth.partials.' . $data['pageName'], $data);
__includes('layouts.footer', $data);
