<?php

namespace Tests;

use Laravel\Dusk\Browser as DuskBrowser;

class Browser extends DuskBrowser
{
    /** @return $this */
    public function waitForHotreload(?int $seconds = null)
    {
        return $this->waitUsing($seconds, 100, fn () => $this->script('return document.querySelector("[data-hotwire-hotreload-ready]") ? true : false') == true);
    }
}
