<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Tracy;


/**
 * Debug Bar.
 */
class Bar
{
    /** @var IBarPanel[] */
    private $panels = [];

    /** @var bool  initialized by dispatchAssets() */
    private $useSession = false;

    /** @var string|NULL  generated by renderLoader() */
    private $contentId;

	/** @var string content encoding */
	private $charset = 'UTF-8';


    /**
     * Add custom panel.
     * @param  IBarPanel
     * @param  string
     * @return static
     */
    public function addPanel(IBarPanel $panel, $id = null)
    {
        if ($id === null) {
            $c = 0;
            do {
                $id = get_class($panel) . ($c++ ? "-$c" : '');
            } while (isset($this->panels[$id]));
        }
        $this->panels[$id] = $panel;
        return $this;
    }


    /**
     * Returns panel with given id
     * @param  string
     * @return IBarPanel|null
     */
    public function getPanel($id)
    {
        return isset($this->panels[$id]) ? $this->panels[$id] : null;
    }

    /**
     * Set content encoding
     *
     * @param string $charset
     */
    public function setCharset($charset) {
        $this->charset = $charset;
    }


    /**
     * Renders loading <script>
     * @return void
     */
    public function renderLoader()
    {
        if (!$this->useSession) {
            throw new \LogicException('Start session before Tracy is enabled.');
        }
        $contentId = $this->contentId = $this->contentId ?: substr(md5(uniqid('', true)), 0, 10);
        $nonce = Helpers::getNonce();
        $async = true;
        require __DIR__ . '/assets/Bar/loader.phtml';
    }


    /**
     * Renders debug bar.
     * @return void
     */
    public function render()
    {
        $sessionHandler = Debugger::getSessionHandler();
        $useSession = $this->useSession && $sessionHandler->isActive();

        if (!$sessionHandler->hasHistoryManagement()) {
            foreach (['redirect', 'bluescreen'] as $key) {
                $queue = $sessionHandler->get($key);

                if ($key === 'redirect') {
                    $queue = array_slice((array)$queue, -10, null, true);
                }

                $queue = array_filter((array)$queue, function ($item) {
                    return isset($item['time']) && $item['time'] > time() - 60;
                });

                $sessionHandler->set($key, $queue);
            }

            foreach (['bar'] as $key) {
                $queue = $sessionHandler->get($key);

                foreach ((array)$queue as $k => $line) {
                    $localKey = ['bar', $key];

                    foreach($line as $item) {
                        if(!isset($item['time']) || $item['time'] <= time() - 60) {
                            $sessionHandler->delete($localKey, $item);
                        }
                    }

                    if (!$line) {
                        $sessionHandler->clear($localKey);
                    }
                }
            }
        }

        if (Helpers::isAjax()) {
            if ($useSession) {
                $contentId = $_SERVER['HTTP_X_TRACY_AJAX'] . '-ajax';

                $row = (object)[
                    'type' => 'ajax',
                    'url' => $_SERVER['REQUEST_URI'],
                    'panels' => $this->renderPanels('-' . uniqid()),
                ];

                $sessionHandler->add(['bar', $contentId], [
                    'content' => self::renderHtmlRows([$row]),
                    'dumps' => Dumper::fetchLiveData(),
                    'time' => time(),
                ]);
            }
        } elseif (preg_match('#^Location:#im', implode("\n", headers_list()))) { // redirect
            if ($useSession) {
                $id = uniqid();
                Dumper::fetchLiveData();
                Dumper::$livePrefix = $id . 'p';

                $sessionHandler->add('redirect', [
                    'panels' => $this->renderPanels('-r' . $id),
                    'dumps' => Dumper::fetchLiveData(),
                    'time' => time(),
                ]);
            }
        } elseif (Helpers::isHtmlMode()) {
            $rows[] = (object)['type' => 'main', 'panels' => $this->renderPanels()];
            $dumps = Dumper::fetchLiveData();
            foreach (array_reverse((array)$sessionHandler->get('redirect')) as $info) {
                $rows[] = (object)['type' => 'redirect', 'panels' => $info['panels']];
                $dumps += $info['dumps'];
            }
            $sessionHandler->set('redirect', null);
            $content = self::renderHtmlRows($rows);

            if ($this->contentId) {
                $sessionHandler->set(['bar', $this->contentId], [[
                    'content' => $content,
                    'dumps' => $dumps,
                    'time' => time(),
                ]]);
            } else {
                $contentId = substr(md5(uniqid('', true)), 0, 10);
                $nonce = Helpers::getNonce();
                $async = false;
                require __DIR__ . '/assets/Bar/loader.phtml';
            }
        }
    }


    /**
     * @return string
     */
    private function renderHtmlRows(array $rows)
    {
        ob_start(function () {
        });
        require __DIR__ . '/assets/Bar/panels.phtml';
        require __DIR__ . '/assets/Bar/bar.phtml';
        return Helpers::fixEncoding(ob_get_clean());
    }


    /**
     * @return array
     */
    private function renderPanels($suffix = null)
    {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (error_reporting() & $severity) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
        });

        $obLevel = ob_get_level();
        $panels = [];

        foreach ($this->panels as $id => $panel) {
            $idHtml = preg_replace('#[^a-z0-9]+#i', '-', $id) . $suffix;
            try {
                $tab = (string)$panel->getTab();
                $panelHtml = $tab ? (string)$panel->getPanel() : null;
                if ($tab && $panel instanceof \Nette\Diagnostics\IBarPanel) {
                    $e = new \Exception('Support for Nette\Diagnostics\IBarPanel is deprecated');
                }

            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
            if (isset($e)) {
                while (ob_get_level() > $obLevel) { // restore ob-level if broken
                    ob_end_clean();
                }
                $idHtml = "error-$idHtml";
                $tab = "Error in $id";
                $panelHtml = "<h1>Error: $id</h1><div class='tracy-inner'>" . nl2br(Helpers::escapeHtml($e)) . '</div>';
                unset($e);
            }
            $panels[] = (object)['id' => $idHtml, 'tab' => $tab, 'panel' => $panelHtml];
        }

        restore_error_handler();
        return $panels;
    }


    /**
     * Renders debug bar assets.
     * @return bool
     */
    public function dispatchAssets()
    {
        $asset = isset($_GET['_tracy_bar']) ? $_GET['_tracy_bar'] : null;
        if ($asset === 'js') {
            header('Content-Type: text/javascript');
            header('Cache-Control: max-age=864000');
            header_remove('Pragma');
            header_remove('Set-Cookie');
            $this->renderAssets();
            return true;
        }

        $sessionHandler = Debugger::getSessionHandler();
        $this->useSession = $sessionHandler->isActive();

        if ($this->useSession && Helpers::isAjax()) {
            header('X-Tracy-Ajax: 1'); // session must be already locked
        }

        if ($this->useSession && $asset && preg_match('#^content(-ajax)?\.(\w+)$#', $asset, $m)) {
            header('Content-Type: text/javascript');
            header('Cache-Control: max-age=60');
            header_remove('Set-Cookie');

            if (!$m[1]) {
                $this->renderAssets();
            }

            $sessionKey = ['bar', $m[2] . $m[1]];
            $session = $sessionHandler->get($sessionKey);

            if ($session) {
                $method = $m[1] ? 'loadAjax' : 'init';

                foreach ($session as $line) {
                    echo "Tracy.Debug.$method(", $this->jsonEncode($line['content']), ', ', $this->jsonEncode($line['dumps']), ');';
                }

                $sessionHandler->clear($sessionKey);
            }

            $sessionKey = ['bluescreen', $m[2]];
            $session = $sessionHandler->get($sessionKey);

			if ($session) {
				echo 'Tracy.BlueScreen.loadAjax(', $this->jsonEncode($session['content']), ', ', $this->jsonEncode($session['dumps']), ');';
                $sessionHandler->clear($sessionKey);
			}

            return true;
        }
    }

    private function jsonEncode($content) {
	    if ($this->charset !== 'UTF-8') {
	        $content = mb_convert_encoding($content, 'UTF-8', $this->charset);
        }

	    $json = json_encode($content);

        if ($this->charset !== 'UTF-8') {
            $json = mb_convert_encoding($json, $this->charset, 'UTF-8');
        }

        return $json;
    }

    private function renderAssets()
    {
        $css = array_map('file_get_contents', array_merge([
            __DIR__ . '/assets/Bar/bar.css',
            __DIR__ . '/assets/Toggle/toggle.css',
            __DIR__ . '/assets/Dumper/dumper.css',
            __DIR__ . '/assets/BlueScreen/bluescreen.css',
        ], Debugger::$customCssFiles));
        $css = json_encode(preg_replace('#\s+#u', ' ', implode($css)));
        echo "(function(){var el = document.createElement('style'); el.className='tracy-debug'; el.textContent=$css; document.head.appendChild(el);})();\n";

        array_map('readfile', array_merge([
            __DIR__ . '/assets/Bar/bar.js',
            __DIR__ . '/assets/Toggle/toggle.js',
            __DIR__ . '/assets/Dumper/dumper.js',
            __DIR__ . '/assets/BlueScreen/bluescreen.js',
        ], Debugger::$customJsFiles));
    }
}
