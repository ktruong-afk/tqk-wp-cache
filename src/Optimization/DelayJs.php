<?php
namespace TQK_WP_CACHE\Optimization;

defined('ABSPATH') || exit;

/**
 * Delays classic JavaScript until the visitor's first interaction.
 *
 * Only executable classic scripts are touched. Everything else is left exactly as WordPress printed it:
 *  - type="module" (deferred by nature; turning it into a classic script is a syntax error for `import`),
 *  - data blocks (application/json, importmap, speculationrules, ld+json, text/template...), which are not JavaScript at all,
 *  - <noscript> content.
 * After the delayed scripts have run, DOMContentLoaded / readystatechange / load are replayed so code that waited for them still starts.
 */
class DelayJs {
    private int $timeout;
    private array $exclusions;

    private const EXECUTABLE_TYPES = [
        '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'text/jscript',
    ];

    public function __construct(int $timeout = 0, string $exclusions_str = '') {
        $this->timeout = max(0, $timeout);
        $lines = explode("\n", str_replace("\r", "", $exclusions_str));
        $this->exclusions = array_values(array_filter(array_map('trim', $lines)));
    }

    public function process(string $html): string {
        // <noscript> and <template> bodies are not part of the live document: park them.
        $parked = [];
        $html = preg_replace_callback('#<(noscript|template)\b[^>]*>.*?</\1\s*>#is', function ($m) use (&$parked) {
            $key = '<!--TQKPARK' . count($parked) . '-->';
            $parked[$key] = $m[0];
            return $key;
        }, $html);

        $processed = preg_replace_callback('#<script\b([^>]*)>(.*?)</script\s*>#is', fn($m) => $this->convert($m[0], $m[1], $m[2]), (string)$html);
        if ($processed === null) {
            $processed = (string)$html; // backtrack limit: leave scripts alone rather than half-convert them
            return $parked ? strtr($processed, $parked) : $processed;
        }

        if ($parked) {
            $processed = strtr($processed, $parked);
        }

        if (strpos($processed, 'type="text/tqk-delayed-js"') === false) {
            return $processed; // nothing delayed, no need for a loader
        }

        $runner = $this->get_loader_script();
        if (stripos($processed, '</body>') !== false) {
            return str_ireplace('</body>', $runner . '</body>', $processed);
        }
        return $processed . $runner;
    }

    private function convert(string $whole, string $attrs, string $content): string {
        $type = '';
        if (preg_match('#\btype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attrs, $t)) {
            $type = strtolower(trim($t[1] !== '' ? $t[1] : ($t[2] ?? '') . ($t[3] ?? '')));
        }
        if (!in_array($type, self::EXECUTABLE_TYPES, true)) {
            return $whole;
        }
        if (preg_match('#\b(?:data-tqk-no-delay|nomodule|data-no-delay)\b#i', $attrs) || stripos($attrs, 'tqk-delay-js-loader') !== false) {
            return $whole;
        }
        foreach ($this->exclusions as $exclude) {
            if (stripos($attrs, $exclude) !== false || stripos($content, $exclude) !== false) {
                return $whole;
            }
        }

        // Remove type=..., remember it so the script runs as it was written.
        $clean = preg_replace('#\s*\btype\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $attrs);
        $keep_type = $type !== '' ? ' data-tqk-type="' . esc_attr($type) . '"' : '';

        if (preg_match('#\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $clean, $s)) {
            $src = $s[1] !== '' ? $s[1] : (($s[2] ?? '') !== '' ? str_replace('"', '&quot;', $s[2]) : ($s[3] ?? ''));
            $clean = preg_replace('#\s*\bsrc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $clean, 1);
            return '<script type="text/tqk-delayed-js" data-tqk-src="' . $src . '"' . $keep_type . $clean . '></script>';
        }

        return '<script type="text/tqk-delayed-js"' . $keep_type . $clean . '>' . $content . '</script>';
    }

    /** The loader is plain ES5 with no line comments, so it still parses if some other tool joins its lines. */
    private function get_loader_script(): string {
        $js = <<<'JS'
(function(){
var done=false,timeoutMs=__TIMEOUT__,opts={passive:true};
var events=['keydown','mousemove','mousedown','touchstart','touchmove','wheel','scroll','click'];
function fire(target,type){try{target.dispatchEvent(new Event(type,{bubbles:true,cancelable:true}));}catch(e){}}
function finish(){
fire(document,'readystatechange');
fire(document,'DOMContentLoaded');
fire(window,'load');
try{window.dispatchEvent(new CustomEvent('tqk_scripts_loaded'));}catch(e){}
}
function run(){
if(done){return;}
done=true;
events.forEach(function(n){window.removeEventListener(n,run,opts);});
var list=[].slice.call(document.querySelectorAll('script[type="text/tqk-delayed-js"]'));
var i=0;
function next(){
if(i>=list.length){finish();return;}
var old=list[i++],s=document.createElement('script');
for(var a=0;a<old.attributes.length;a++){
var at=old.attributes[a];
if(at.name!=='type'&&at.name!=='data-tqk-src'&&at.name!=='data-tqk-type'){s.setAttribute(at.name,at.value);}
}
var t=old.getAttribute('data-tqk-type');
if(t){s.type=t;}
var src=old.getAttribute('data-tqk-src');
if(src){
s.async=false;
s.onload=s.onerror=next;
s.src=src;
old.parentNode.replaceChild(s,old);
}else{
s.text=old.text;
old.parentNode.replaceChild(s,old);
next();
}
}
next();
}
function arm(){
events.forEach(function(n){window.addEventListener(n,run,opts);});
if(timeoutMs>0){setTimeout(run,timeoutMs);}
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',arm);}else{arm();}
})();
JS;
        $js = str_replace('__TIMEOUT__', (string)($this->timeout * 1000), $js);
        return '<script id="tqk-delay-js-loader">' . $js . '</script>';
    }
}
