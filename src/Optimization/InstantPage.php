<?php
namespace TQK_WP_CACHE\Optimization;

defined('ABSPATH') || exit;

/**
 * Prefetches a page when the visitor is about to open it (hover for 65ms, or touchstart).
 *
 * A prefetch is a real GET with the visitor's cookies, so it must never be sent to a link that DOES something:
 * add-to-cart, remove-item, logout, nonce links, downloads, anything with a query string we do not know is read-only.
 * The old version had no such filter on touch, so touching "Add to cart" on a phone added the product twice.
 */
class InstantPage {
    public function process(string $html): string {
        $script = <<<'JS'
<script id="tqk-instant-page" data-tqk-no-delay>
(function(){
if(!document.createElement('link').relList||!document.createElement('link').relList.supports('prefetch')){return;}
var c=navigator.connection;
if(c&&(c.saveData||/(^|-)2g$/.test(c.effectiveType||''))){return;}
var done={},timer=null,count=0,MAX=8,DELAY=65;
var DENY=/(^|[?&\/])(add-to-cart|add_to_cart|remove_item|removed_item|undo_item|logout|log-out|wp-login|wp-admin|_wpnonce|nonce|action|download|unsubscribe|order_again|add-to-wishlist|wc-ajax|wc-api)([=&\/.]|$)/i;
function ok(a){
if(!a||!a.href||a.origin!==location.origin){return false;}
if(a.target&&a.target!=='_self'){return false;}
if(a.hasAttribute('download')||a.hasAttribute('data-no-instant')||a.closest('[data-no-instant]')){return false;}
if(a.protocol!=='http:'&&a.protocol!=='https:'){return false;}
if(a.pathname===location.pathname&&a.search===location.search){return false;}
if(DENY.test(a.pathname+a.search)){return false;}
if(a.search&&/[?&](p|page_id|cat|tag|paged|page|post_type|m)=/.test(a.search)===false){return false;}
return true;
}
function go(url){
if(done[url]||count>=MAX){return;}
done[url]=1;count++;
var l=document.createElement('link');
l.rel='prefetch';l.href=url;l.as='document';
document.head.appendChild(l);
}
function link(e){return e.target&&e.target.closest?e.target.closest('a'):null;}
document.addEventListener('mouseover',function(e){
var a=link(e);
if(!ok(a)){return;}
clearTimeout(timer);
timer=setTimeout(function(){go(a.href);},DELAY);
},{passive:true});
document.addEventListener('mouseout',function(){clearTimeout(timer);},{passive:true});
document.addEventListener('touchstart',function(e){
var a=link(e);
if(ok(a)){go(a.href);}
},{passive:true});
})();
</script>
JS;

        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $script . '</body>', $html);
        }
        return $html . $script;
    }
}
