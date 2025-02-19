<?php

namespace Tests;

use Laravel\Dusk\Browser as DuskBrowser;
use Override;

class Browser extends DuskBrowser
{
    /** @return $this */
    #[Override]
    public function waitForText($text, $seconds = null, $ignoreCase = false)
    {
        return parent::waitForText($text, $seconds, $ignoreCase)->assertSee($text);
    }

    /** @return $this */
    #[Override]
    public function waitUntilMissingText($text, $seconds = null)
    {
        return parent::waitUntilMissingText($text, $seconds)->assertDontSee($text);
    }

    /** @return $this */
    public function waitForHotreload(?int $seconds = null)
    {
        return $this->waitUsing($seconds, 100, fn () => $this->script('return document.querySelector("[data-hotwire-hotreload-ready]") ? true : false') == true);
    }

    /** {@inheritdoc} */
    #[Override]
    public function visit($url)
    {
        return parent::visit($url)->waitForHotreload();
    }
}
