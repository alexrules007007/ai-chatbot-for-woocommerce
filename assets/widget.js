/* AI Chatbot for WooCommerce - Frontend Widget JS */

(function () {
    'use strict';

    if (typeof WCAI_CONFIG === 'undefined') {
        console.warn('WCAI: Config not found');
        return;
    }

    var AJAX    = WCAI_CONFIG.ajaxUrl;
    var NONCE   = WCAI_CONFIG.nonce;
    var WELCOME = WCAI_CONFIG.welcome;
    var SK      = 'wcai_v8_history';
    var FONT    = WCAI_CONFIG.font || 'system-ui, sans-serif';

        window.wcaiShowMoreOpenText  = WCAI_CONFIG.showMoreOpen || '📖 Show more...';
    window.wcaiShowMoreCloseText = WCAI_CONFIG.showMoreClose || '📖 Hide details';

    function getCookie(name) {
        var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
        return m ? decodeURIComponent(m[1]) : '';
    }
    function getActiveCurrency() {
        return getCookie('woocs_current_currency') || getCookie('wmc_currency') || WCAI_CONFIG.defaultCurrency || 'USD';
    }

    var box    = document.getElementById('wcai-box');
    var btn    = document.getElementById('wcai-btn');
    var closeBtn = document.getElementById('wcai-close');
    var clear  = document.getElementById('wcai-clear');
    var send   = document.getElementById('wcai-send');
    var input  = document.getElementById('wcai-input');
    var msgs   = document.getElementById('wcai-msgs');

    if (!box || !btn || !msgs || !input || !send) return;

    var busy = false;
    var seen = new Set();

    function getSessionId() {
        var key = 'wcai_session_id';
        var sid = localStorage.getItem(key);
        if (!sid) {
            sid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                var v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
            localStorage.setItem(key, sid);
        }
        return sid;
    }
    var SESSION_ID = getSessionId();

    function load() { try { return JSON.parse(localStorage.getItem(SK)) || []; } catch(e) { return []; } }
    function save(h) { try { localStorage.setItem(SK, JSON.stringify(h.slice(-100))); } catch(e) {} }
    function uid() { return Date.now() + '_' + Math.random().toString(36).slice(2, 8); }

    function addMsg(text, isBot, isHtml, id) {
        id = id || uid();
        if (seen.has(id)) return;
        seen.add(id);

        var el = document.createElement('div');
        el.className = 'wcai-msg ' + (isBot ? 'bot' : 'user');
        el.dataset.id = id;

        if (isBot) {
            if (!text || text.trim() === '') return;
            el.innerHTML = text;
            el.style.fontFamily = FONT;
            el.querySelectorAll('*').forEach(function(child) { child.style.fontFamily = FONT; });
        } else {
            el.textContent = text;
        }

        msgs.appendChild(el);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function render() {
        msgs.innerHTML = '';
        seen.clear();
        var h = load().filter(function(m) { return m.role === 'user' || m.role === 'assistant'; });
        if (!h.length) { addMsg(WELCOME, true, false); return; }
        h.forEach(function (m) {
            addMsg(m.content, m.role === 'assistant', true, m.id);
        });
    }

    function push(role, content, products) {
        var h = load();
        var entry = { id: uid(), role: role, content: content, timestamp: new Date().toISOString() };
        if (products && products.length) entry.products = products;
        h.push(entry);
        save(h);
    }

    function showTyping() {
        var t = document.createElement('div');
        t.className = 'wcai-typing';
        t.id = 'wcai-typing';
        t.innerHTML = '<span class="wcai-thinking-dots"><span class="wcai-thinking-dot"></span><span class="wcai-thinking-dot"></span><span class="wcai-thinking-dot"></span><span class="wcai-thinking-dot"></span><span class="wcai-thinking-dot"></span></span>';
        msgs.appendChild(t);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function hideTyping() {
        var t = document.getElementById('wcai-typing');
        if (t) t.remove();
    }

    function doSend() {
        var text = input.value.trim();
        if (!text || busy) return;

        var h = load();
        for (var i = h.length - 1; i >= 0; i--) {
            if (h[i].role === 'user' && h[i].content === text) {
                addMsg('You already sent this message.', true, false);
                return;
            }
        }

        addMsg(text, false, false);
        push('user', text);
        input.value = '';
        showTyping();
        busy = true;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;

        xhr.onload = function () {
            hideTyping();
            busy = false;

            if (xhr.status !== 200) {
                addMsg('Server error (' + xhr.status + ')', true, false);
                return;
            }
            try {
                var res = JSON.parse(xhr.responseText);
                if (!res.success) {
                    addMsg('⚠️ ' + (res.data.message || 'Unknown error'), true, false);
                    return;
                }
                var reply = res.data.reply || 'No response';
                var products = res.data.products || [];

                push('assistant', reply, products);
                addMsg(reply, true, true);
            } catch(e) {
                addMsg('Parse error', true, false);
            }
        };
        xhr.onerror = function () { hideTyping(); busy = false; addMsg('Network error', true, false); };
        xhr.ontimeout = function () { hideTyping(); busy = false; addMsg('Timeout', true, false); };

                var pageContext = {
            url: window.location.href,
            title: document.title,
            page_type: 'other',
            product_id: null,
            product_name: null,
            product_price: null,
            category: null
        };

        if (document.body.classList.contains('single-product')) {
            pageContext.page_type = 'product';
            var t = document.querySelector('h1.product_title, h1.entry-title');
            if (t) pageContext.product_name = t.textContent.trim();
            var pidInput = document.querySelector('input[name="product_id"], input[name="add-to-cart"]');
            if (pidInput) pageContext.product_id = parseInt(pidInput.value) || null;
            var priceEl = document.querySelector('.summary .price');
            if (priceEl) pageContext.product_price = priceEl.textContent.trim();
            var catEl = document.querySelector('.posted_in a, .product_meta a[rel="tag"]');
            if (catEl) pageContext.category = catEl.textContent.trim();
        } else if (document.body.classList.contains('archive') || document.body.classList.contains('tax-product_cat')) {
            pageContext.page_type = 'category';
            var ct = document.querySelector('h1.page-title, h1.entry-title');
            if (ct) pageContext.category = ct.textContent.trim();
        } else if (document.body.classList.contains('woocommerce-cart')) {
            pageContext.page_type = 'cart';
        } else if (document.body.classList.contains('woocommerce-checkout')) {
            pageContext.page_type = 'checkout';
        } else if (document.body.classList.contains('home') || document.body.classList.contains('front-page')) {
            pageContext.page_type = 'home';
        }

        xhr.send([
            'action=wcai_send',
            'nonce=' + encodeURIComponent(NONCE),
            'message=' + encodeURIComponent(text),
            'history=' + encodeURIComponent(JSON.stringify(load())),
            'currency=' + encodeURIComponent(getActiveCurrency()),
            'session_id=' + encodeURIComponent(SESSION_ID),
            'page_context=' + encodeURIComponent(JSON.stringify(pageContext)),
        ].join('&'));
    }

    btn.onclick = function () {
        box.classList.add('wcai-open');
        btn.style.display = 'none';
        input.focus();
        render();
    };
    if (closeBtn) {
        closeBtn.onclick = function () {
            box.classList.remove('wcai-open');
            btn.style.display = '';
        };
    }
    if (clear) {
        clear.onclick = function () {
            if (!confirm('Clear chat history?')) return;
            localStorage.removeItem(SK);
            msgs.innerHTML = '';
            seen.clear();
            addMsg(WELCOME, true, false);
        };
    }
    send.onclick = doSend;
    input.onkeydown = function (e) { if (e.key === 'Enter') doSend(); };

    if (!load().length) push('assistant', WELCOME);

    document.addEventListener('click', function(e) {
        var target = e.target.closest('.wcai-show-details');
        if (target) {
            var productId = target.getAttribute('data-product-id');
            var productName = target.getAttribute('data-product-name');
            var containerId = target.getAttribute('data-container-id') || 'product-details';
            showProductDetails(productId, productName, containerId);
        }
    });

    window.showProductDetails = function(productId, productName, containerId) {
        if (!containerId) containerId = 'product-details';
        var container = document.getElementById(containerId);
        if (!container) return;

        productId = String(productId).replace(/[^0-9]/g, '');
        if (!productId) return;

        var showMoreOpenText  = window.wcaiShowMoreOpenText || '📖 Show more...';
        var showMoreCloseText = window.wcaiShowMoreCloseText || '📖 Hide details';

        function esc(str) {
            var d = document.createElement('div');
            d.textContent = String(str);
            return d.innerHTML;
        }

        function extractVideoId(text) {
            if (!text) return null;
            var m = text.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/i) ||
                    text.match(/[?&]v=([a-zA-Z0-9_-]{11})/i) ||
                    text.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/i) ||
                    text.match(/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/i);
            return m ? m[1] : null;
        }

        function removeVideo() {
            var w = container.parentNode.querySelector('.wcai-video-wrapper');
            if (w) w.remove();
        }

        function createVideo(videoId) {
            removeVideo();
            var wrapper = document.createElement('div');
            wrapper.className = 'wcai-video-wrapper';
            wrapper.style.cssText = 'flex:0 0 100%;max-width:420px;margin:0 auto 12px auto;';
            var inner = document.createElement('div');
            inner.style.cssText = 'position:relative;padding-bottom:56.25%;height:0;border-radius:10px;overflow:hidden;background:#000;';
            var iframe = document.createElement('iframe');
            iframe.src = 'https://www.youtube-nocookie.com/embed/' + videoId + '?rel=0';
            iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:none;';
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            iframe.setAttribute('loading', 'lazy');
            inner.appendChild(iframe);
            wrapper.appendChild(inner);
            container.parentNode.insertBefore(wrapper, container);
        }

        if (container.style.display === 'block' && container.dataset.productId == productId) {
            container.style.display = 'none';
            container.dataset.productId = '';
            document.querySelectorAll('.wcai-show-details[data-product-id="' + productId + '"]').forEach(function(b) {
                b.textContent = showMoreOpenText;
            });
            removeVideo();
            return;
        }

        document.querySelectorAll('[id^="product-details-"]').forEach(function(el) {
            if (el.style.display === 'block' && el.dataset.productId != productId) {
                el.style.display = 'none';
                var pid = el.dataset.productId;
                el.dataset.productId = '';
                document.querySelectorAll('.wcai-show-details[data-product-id="' + pid + '"]').forEach(function(b) {
                    b.textContent = showMoreOpenText;
                });
                var w = el.parentNode.querySelector('.wcai-video-wrapper');
                if (w) w.remove();
            }
        });

        container.style.display = 'block';
        container.dataset.productId = productId;
        container.innerHTML = '⏳ Loading...';

        document.querySelectorAll('.wcai-show-details[data-product-id="' + productId + '"]').forEach(function(b) {
            b.textContent = showMoreCloseText;
        });

                removeVideo();

        var restUrl = window.location.origin + '/wp-json/wc-ai/v1/product-details-public';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', restUrl);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onload = function() {
            if (xhr.status !== 200) {
                container.innerHTML = '⚠️ Error (HTTP ' + xhr.status + ')';
                return;
            }
            try {
                var p = JSON.parse(xhr.responseText);
                if (p.error) {
                    container.innerHTML = '⚠️ ' + esc(p.error);
                    return;
                }

                var videoId = extractVideoId(p.description || '');
                if (videoId) createVideo(videoId);

                var s = {
                    name: esc(p.name || 'Product'),
                    price: esc(p.price || ''),
                    regular_price: esc(p.regular_price || ''),
                    sale_price: esc(p.sale_price || ''),
                    image: esc(p.image || ''),
                    url: esc(p.url || '#'),
                    brand: esc(p.brand || ''),
                    category: esc(p.category || ''),
                    sku: esc(p.sku || ''),
                    description: esc(p.description || ''),
                    material: esc(p.material || ''),
                    difficulty: esc(p.difficulty || ''),
                    in_stock: !!p.in_stock,
                    rating: parseFloat(p.rating) || 0,
                    rating_count: parseInt(p.rating_count) || 0,
                    colors: parseInt(p.colors) || 0,
                    stitches: parseInt(p.stitches) || 0,
                    available_sizes: Array.isArray(p.available_sizes) ? p.available_sizes.map(esc) : []
                };

                var html = '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;padding:6px 0;">';
                if (s.image) html += '<div style="flex:0 0 130px;"><img src="' + s.image + '" style="width:100%;border-radius:8px;border:1px solid #e5e7eb;" alt="' + s.name + '"></div>';
                html += '<div style="flex:1;min-width:180px;">';
                html += '<div style="font-weight:700;font-size:15px;">' + s.name + '</div>';
                if (p.is_on_sale && p.sale_price) {
                    html += '<div style="font-weight:700;color:#dc2626;font-size:20px;margin:4px 0;"><span style="text-decoration:line-through;color:#9ca3af;font-size:13px;">' + s.regular_price + '</span> ' + s.price + '</div>';
                } else if (s.price) {
                    html += '<div style="font-weight:700;color:#22c55e;font-size:20px;margin:4px 0;">' + s.price + '</div>';
                }
                if (s.rating > 0) html += '<div style="font-size:13px;color:#6b7280;">⭐ ' + s.rating.toFixed(1) + ' (' + s.rating_count + ' reviews)</div>';

                var info = [];
                if (s.brand) info.push('🏷️ Brand: ' + s.brand);
                if (s.category) info.push('📂 Category: ' + s.category);
                if (s.sku) info.push('🔢 SKU: ' + s.sku);
                var d = s.description.replace(/\[embed\][^\]]*\[\/embed\]/g, '').replace(/<iframe[^>]*>.*?<\/iframe>/gi, '').trim();
                if (d) info.push(d);
                if (info.length) html += '<div style="margin-top:6px;font-size:13px;color:#4b5563;line-height:1.6;">' + info.join('<br>') + '</div>';
                if (s.available_sizes && s.available_sizes.length) {
                    html += '<div style="margin-top:8px;font-size:12px;color:#6b7280;background:#f9fafb;padding:6px 10px;border-radius:6px;border:1px solid #e5e7eb;">📏 Sizes: ' + s.available_sizes.join(' · ') + '</div>';
                }
                if (s.material) html += '<div style="margin-top:4px;font-size:12px;color:#6b7280;">🧵 Material: ' + s.material + '</div>';
                if (s.colors) html += '<div style="margin-top:4px;font-size:12px;color:#6b7280;">🎨 Colors: ' + s.colors + '</div>';
                if (s.stitches) html += '<div style="margin-top:4px;font-size:12px;color:#6b7280;">🪡 Stitches: ' + s.stitches + '</div>';
                if (s.difficulty) html += '<div style="margin-top:4px;font-size:12px;color:#6b7280;">📊 Difficulty: ' + s.difficulty + '</div>';
                html += '<div style="margin-top:4px;font-size:12px;color:' + (s.in_stock ? '#22c55e' : '#dc2626') + ';">' + (s.in_stock ? '✅ In stock' : '❌ Out of stock') + '</div>';
                html += '<div style="margin-top:8px;"><a href="' + s.url + '" target="_blank" rel="noopener" style="background:#4F46E5;color:#fff;padding:6px 16px;border-radius:20px;text-decoration:none;font-size:12px;font-weight:600;">🔗 View Product</a></div>';
                html += '</div></div>';

                container.innerHTML = html;
                setTimeout(function() {
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 200);
            } catch(e) {
                container.innerHTML = '⚠️ Error loading details';
            }
        };
        xhr.onerror = function() {
            container.innerHTML = '⚠️ Network error';
        };
        xhr.send(JSON.stringify({ product_id: productId }));
    };
})();

(function() {
    var font = (typeof WCAI_CONFIG !== 'undefined' && WCAI_CONFIG.font) || 'system-ui, sans-serif';
    function fixFonts() {
        document.querySelectorAll('.wcai-product-grid, .wcai-product-grid *').forEach(function(el) {
            el.style.setProperty('font-family', font, 'important');
        });
    }
    fixFonts();
    new MutationObserver(fixFonts).observe(document.body, { childList: true, subtree: true });
})();

(function() {
    function isDarkMode() {
        var box = document.getElementById('wcai-box');
        if (!box) return false;
        return getComputedStyle(box).backgroundColor === 'rgb(31, 41, 55)';
    }

    function fixCards() {
        if (!isDarkMode()) return;
        document.querySelectorAll('.wcai-msg.bot').forEach(function(msg) {
            msg.querySelectorAll('div').forEach(function(el) {
                var style = el.getAttribute('style') || '';
                if (style.includes('background') || style.includes('border') || style.includes('padding')) {
                    if (!style.includes('#4F46E5') && !style.includes('transparent')) {
                        el.style.setProperty('background', '#1f2937', 'important');
                    }
                    el.style.setProperty('color', '#f3f4f6', 'important');
                    el.style.setProperty('border-color', '#374151', 'important');
                }
                if (style.includes('color') && !style.includes('#4ade80') && !style.includes('green') && !style.includes('#4F46E5')) {
                    el.style.setProperty('color', '#f3f4f6', 'important');
                }
            });
            msg.querySelectorAll('[style*="color"][style*="#22c55e"]').forEach(function(el) {
                el.style.setProperty('color', '#4ade80', 'important');
            });
            msg.querySelectorAll('[style*="background"][style*="#f9fafb"], [style*="background"][style*="#f3f4f6"]').forEach(function(el) {
                el.style.setProperty('background', '#111827', 'important');
            });
            msg.querySelectorAll('button, .wcai-show-details').forEach(function(el) {
                el.style.setProperty('background', '#374151', 'important');
                el.style.setProperty('color', '#f3f4f6', 'important');
                el.style.setProperty('border', '1px solid #4b5563', 'important');
                el.style.setProperty('border-radius', '20px', 'important');
                el.style.setProperty('padding', '6px 14px', 'important');
            });
        });
    }

    function fixCompareTables() {
        if (!isDarkMode()) return;
        document.querySelectorAll('.wcai-msg.bot table').forEach(function(table) {
            table.style.setProperty('background', '#1a202c', 'important');
            table.style.setProperty('border-color', '#2d3748', 'important');
            table.style.setProperty('color', '#ffffff', 'important');
            table.querySelectorAll('tr td, tr th').forEach(function(cell) {
                cell.style.setProperty('color', '#ffffff', 'important');
                if (cell.tagName === 'TH' || cell.parentElement.rowIndex === 0) {
                    cell.style.setProperty('background', '#0f172a', 'important');
                    cell.style.setProperty('font-weight', '700', 'important');
                } else {
                    cell.style.setProperty('background', '#1a202c', 'important');
                }
                cell.style.setProperty('border-color', '#2d3748', 'important');
            });
        });
    }

    var observer = new MutationObserver(function() {
        setTimeout(function() { fixCards(); fixCompareTables(); }, 50);
    });

    document.addEventListener('DOMContentLoaded', function() {
        var box = document.getElementById('wcai-box');
        if (box) observer.observe(box, { childList: true, subtree: true });
        var btn = document.getElementById('wcai-btn');
        if (btn) btn.addEventListener('click', function() {
            setTimeout(fixCards, 300);
            setTimeout(fixCompareTables, 300);
        });
        setInterval(function() { fixCards(); fixCompareTables(); }, 2000);
    });
})();
