
        (function() {
            'use strict';

            var REST_URL = 'x';
            var AJAX_URL = 'x';

            function getCookie(name) {
                var m = document.cookie.match(new RegExp('(^|; )' + name.replace(/([\.0*|{}\(\)\[\]\\/\+^])/g, '\$1') + '=([^;]*)'));
                return m ? decodeURIComponent(m[2]) : null;
            }

            function setCookie(name, val, days) {
                var d = new Date();
                d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
                document.cookie = name + '=' + encodeURIComponent(val) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
            }

            var params = new URLSearchParams(window.location.search);
            var gclid = params.get('gclid') || params.get('gbraid') || params.get('wbraid') || '';
            var gadSource = params.get('gad_source') || '';
            var gadCampaign = params.get('gad_campaignid') || '';
            var utmSource = params.get('utm_source') || '';
            var utmMedium = params.get('utm_medium') || '';
            var utmCampaign = params.get('utm_campaign') || '';
            // Google Ads keyword via Final URL suffix: utm_term={keyword} (ignore unreplaced ValueTrack braces)
            var utmTerm = (params.get('utm_term') || params.get('keyword') || '').replace(/[{}]/g, '').substring(0, 200);

            var ref = document.referrer || '';
            var channel = 'Direct';

            if (gclid || gadSource || utmMedium.toLowerCase() === 'cpc' || utmSource.toLowerCase().includes('google-ads')) {
                channel = 'Google Ads';
            } else if (ref.includes('chatgpt.com') || ref.includes('chat.openai.com') || ref.includes('com.openai.chatgpt')) {
                channel = 'ChatGPT (AI)';
            } else if (ref.includes('perplexity.ai')) {
                channel = 'Perplexity (AI)';
            } else if (ref.includes('claude.ai')) {
                channel = 'Claude (AI)';
            } else if (ref.includes('google.') && !gclid) {
                channel = 'Google Organic (SEO)';
            } else if (ref.includes('bing.com')) {
                channel = 'Bing Organic';
            } else if (ref.includes('yahoo.com')) {
                channel = 'Yahoo Organic';
            } else if (ref.includes('duckduckgo.com')) {
                channel = 'DuckDuckGo';
            } else if (ref && !ref.includes(window.location.hostname)) {
                try {
                    var urlObj = new URL(ref);
                    channel = 'Referral: ' + urlObj.hostname;
                } catch(e) {
                    channel = 'Referral';
                }
            }

            var existingChannel = getCookie('_spc_attr_channel');
            var isInternalNav = ref.includes(window.location.hostname);

            if (!existingChannel || (!isInternalNav && channel !== 'Direct')) {
                setCookie('_spc_attr_channel', channel, 30);
                setCookie('_spc_attr_landing', window.location.href, 30);
                setCookie('_spc_attr_ref', ref, 30);
                setCookie('_spc_attr_gclid', gclid, 30);
                setCookie('_spc_attr_campaign', utmCampaign || gadCampaign, 30);
                setCookie('_spc_attr_gad', gadCampaign, 30);
                setCookie('_spc_attr_term', utmTerm, 30);
                // When this visit's source was recorded (shows if an Ads lead clicked the ad earlier)
                setCookie('_spc_attr_time', String(Date.now()), 30);
            }

            function newEventId() {
                try {
                    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
                } catch(e) {}
                return 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
            }

            function getDeviceInfo() {
                var ua = navigator.userAgent || '';
                var width = window.innerWidth || (screen && screen.width) || 1024;
                var isMob = /Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua) || (width <= 768);
                var isTab = /iPad|tablet/i.test(ua) || (width > 768 && width <= 1024);
                var dev = isMob ? 'Mobile' : (isTab ? 'Tablet' : 'Desktop');

                var browser = 'Unknown';
                if (ua.includes('Edg/')) browser = 'Edge';
                else if (ua.includes('Chrome/')) browser = 'Chrome';
                else if (ua.includes('Safari/') && !ua.includes('Chrome/')) browser = 'Safari';
                else if (ua.includes('Firefox/')) browser = 'Firefox';

                var os = 'Unknown';
                if (ua.includes('Windows')) os = 'Windows';
                else if (ua.includes('Android')) os = 'Android';
                else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';
                else if (ua.includes('Mac OS')) os = 'macOS';
                else if (ua.includes('Linux')) os = 'Linux';

                return { device: dev, browser: browser, os: os };
            }

            var lastLogTime = 0;
            function logEvent(type, target, details, eventId) {
                var now = Date.now();
                if (now - lastLogTime < 1500) return;
                lastLogTime = now;

                var dev = getDeviceInfo();
                var curUrl = window.location.href;
                var landUrl = getCookie('_spc_attr_landing') || curUrl;
                var effChannel = getCookie('_spc_attr_channel') || channel;
                var effRef = getCookie('_spc_attr_ref') || ref;
                var effCampaign = getCookie('_spc_attr_campaign') || utmCampaign || gadCampaign;
                var effGclid = getCookie('_spc_attr_gclid') || gclid;
                var effGad = getCookie('_spc_attr_gad') || gadCampaign;

                var payload = {
                    action: 'spc_track_lead',
                    event_id: eventId || newEventId(),
                    attr_time: getCookie('_spc_attr_time') || '',
                    search_term: getCookie('_spc_attr_term') || utmTerm,
                    lead_type: type,
                    phone_or_target: target || '02084323282',
                    channel: effChannel,
                    referrer_url: effRef,
                    landing_page: landUrl,
                    current_page: curUrl,
                    utm_source: utmSource,
                    utm_medium: utmMedium,
                    utm_campaign: effCampaign,
                    gclid: effGclid,
                    gad_campaignid: effGad,
                    device_type: dev.device,
                    browser: dev.browser,
                    os: dev.os,
                    lead_details: details || ''
                };

                // 1. CORS-safelisted FormData via sendBeacon
                var formData = new FormData();
                for (var key in payload) {
                    if (payload.hasOwnProperty(key)) {
                        formData.append(key, payload[key]);
                    }
                }

                var beaconSent = false;
                if (navigator.sendBeacon) {
                    try {
                        beaconSent = navigator.sendBeacon(AJAX_URL, formData);
                    } catch(e) {
                        beaconSent = false;
                    }
                }

                // 3. Image ping (GET 1x1 GIF) - only used when beacon and fetch both fail.
                // The same event_id travels with every transport, so the server stores one row.
                function imagePing() {
                    try {
                        var qParts = [];
                        for (var pk in payload) {
                            if (payload.hasOwnProperty(pk)) {
                                qParts.push(encodeURIComponent(pk) + '=' + encodeURIComponent(payload[pk]));
                            }
                        }
                        var imgPing = new Image();
                        imgPing.src = AJAX_URL + '&img=1&' + qParts.join('&');
                    } catch(e) {}
                }

                // 2. Fetch with keepalive: true (POST FormData)
                if (!beaconSent) {
                    if (window.fetch) {
                        try {
                            fetch(AJAX_URL, {
                                method: 'POST',
                                body: formData,
                                keepalive: true
                            }).catch(function() { imagePing(); });
                        } catch(e) { imagePing(); }
                    } else {
                        imagePing();
                    }
                }
            }

            // Dynamically inject exact page URL into all forms & modals on the page
            function attachFormUrlFields() {
                var curUrl = window.location.href;
                var refUrl = document.referrer || '';
                var forms = document.querySelectorAll('form');
                for (var i = 0; i < forms.length; i++) {
                    var f = forms[i];
                    var spcInput = f.querySelector('input[name=spc_page_url]');
                    if (!spcInput) {
                        spcInput = document.createElement('input');
                        spcInput.type = 'hidden';
                        spcInput.name = 'spc_page_url';
                        f.appendChild(spcInput);
                    }
                    spcInput.value = curUrl;

                    // One event id per form submission, shared by the browser event and the server-side form hook
                    var evInput = f.querySelector('input[name=spc_event_id]');
                    if (!evInput) {
                        evInput = document.createElement('input');
                        evInput.type = 'hidden';
                        evInput.name = 'spc_event_id';
                        f.appendChild(evInput);
                    }
                    if (!evInput.value) evInput.value = newEventId();

                    var curInput = f.querySelector('input[name=current_url]');
                    if (curInput) {
                        curInput.value = curUrl;
                    }

                    var refInput = f.querySelector('input[name=referer_url]');
                    if (refInput && !refInput.value) {
                        refInput.value = refUrl;
                    }
                }
            }

            window.spcAttachFormUrls = attachFormUrlFields;
            attachFormUrlFields();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', attachFormUrlFields);
            }
            window.addEventListener('load', attachFormUrlFields);

            document.addEventListener('focusin', function(e) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT')) {
                    attachFormUrlFields();
                }
            }, true);

            document.addEventListener('submit', function(e) {
                attachFormUrlFields();
            }, true);

            if (window.jQuery) {
                jQuery(document).on('before:forminator:form:submit', function(e, formData) {
                    attachFormUrlFields();
                    if (formData && typeof formData.set === 'function') {
                        formData.set('spc_page_url', window.location.href);
                        formData.set('current_url', window.location.href);
                        var evEl = e.target && e.target.querySelector ? e.target.querySelector('input[name=spc_event_id]') : null;
                        if (evEl && evEl.value) formData.set('spc_event_id', evEl.value);
                    }
                });

                jQuery(document).on('forminator:form:submit:success', function(e, formData, response) {
                    var form = e.target;
                    var formId = (form && form.getAttribute('data-form-id')) || 'form';
                    var evEl = form && form.querySelector ? form.querySelector('input[name=spc_event_id]') : null;
                    var formEventId = (evEl && evEl.value) ? evEl.value : newEventId();
                    if (evEl) evEl.value = newEventId(); // fresh id for any further submission
                    var nameInput = form ? form.querySelector('input[name*="name"]') : null;
                    var phoneInput = form ? form.querySelector('input[name*="phone"]') : null;
                    var emailInput = form ? form.querySelector('input[name*="email"]') : null;
                    var details = 'Form submission on ' + window.location.pathname;
                    if (nameInput && nameInput.value) details += ' | Name: ' + nameInput.value;
                    if (phoneInput && phoneInput.value) details += ' | Tel: ' + phoneInput.value;
                    if (emailInput && emailInput.value) details += ' | Email: ' + emailInput.value;

                    lastLogTime = 0; // a form submit must never be skipped by the click throttle
                    logEvent('form_submission', 'Form #' + formId, details, formEventId);
                });
            }

            // Universal Call Target Detector
            function findCallTarget(node) {
                var el = node;
                if (el && el.nodeType === 3) el = el.parentElement;

                for (var i = 0; i < 10 && el && el !== document.body && el !== document.documentElement; i++) {
                    // Non-interactive text blocks must NEVER trigger a call unless an actual <a> link inside was clicked
                    if (el.classList && el.classList.contains('elementor-widget-text-editor')) {
                        if (node === el || (!node.closest || !node.closest('a[href*="tel:"]'))) {
                            return null;
                        }
                    }

                    // 1. Direct or parent href containing tel:
                    var href = (el.getAttribute && el.getAttribute('href')) || (typeof el.href === 'string' ? el.href : '') || '';
                    href = href.trim();
                    if (/tel:/i.test(href)) {
                        var m = href.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawP = m ? m[1] : href.replace(/^.*tel:/i, '');
                        try { rawP = decodeURIComponent(rawP); } catch(e) {}
                        var cleanP = rawP.replace(/[\s\(\)\-\.]/g, '');
                        var label = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || 'Call Button').trim().substring(0, 80);
                        return { phone: cleanP || '02084323282', label: label };
                    }

                    // 2. Elementor XPro widget link or data attributes
                    var xpro = (el.getAttribute && el.getAttribute('data-xpro-element-link')) || '';
                    if (/tel:/i.test(xpro)) {
                        var mX = xpro.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawPX = mX ? mX[1] : '';
                        try { rawPX = decodeURIComponent(rawPX); } catch(e) {}
                        var cleanPX = rawPX.replace(/[\s\(\)\-\.]/g, '');
                        var labelX = (el.innerText || 'Call Button (XPro)').trim().substring(0, 80);
                        return { phone: cleanPX || '02084323282', label: labelX };
                    }

                    // 3. Custom data-href, data-url or onclick with tel:
                    var dHref = (el.getAttribute && (el.getAttribute('data-href') || el.getAttribute('data-url') || el.getAttribute('onclick'))) || '';
                    if (/tel:/i.test(dHref)) {
                        var mD = dHref.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawPD = mD ? mD[1] : '';
                        try { rawPD = decodeURIComponent(rawPD); } catch(e) {}
                        var cleanPD = rawPD.replace(/[\s\(\)\-\.]/g, '');
                        var labelD = (el.innerText || 'Call Button').trim().substring(0, 80);
                        return { phone: cleanPD || '02084323282', label: labelD };
                    }

                    // 4. Interactive button or link containing phone number in text
                    var isInteractive = el.tagName === 'A' || el.tagName === 'BUTTON' || 
                        (el.getAttribute && el.getAttribute('role') === 'button') ||
                        (el.classList && (
                            el.classList.contains('elementor-button') || 
                            el.classList.contains('ast-custom-button-link') || 
                            el.classList.contains('ast-custom-button') ||
                            el.classList.contains('menu-link') ||
                            el.classList.contains('btn') ||
                            el.classList.contains('call-btn')
                        ));

                    if (isInteractive) {
                        var txt = (el.innerText || el.getAttribute('aria-label') || '').trim();
                        if (txt && (txt.indexOf('02084323282') !== -1 || txt.indexOf('020 8432 3282') !== -1 || /\b020[\s\-\.]?8432[\s\-\.]?3282\b/.test(txt))) {
                            return { phone: '02084323282', label: txt.substring(0, 80) };
                        }
                    }

                    el = el.parentElement;
                }
                return null;
            }

            var lastCallTrigger = 0;
            function handleCallEvent(e) {
                if (!e || !e.target) return;
                if (e.isTrusted === false) return;

                var callInfo = findCallTarget(e.target);
                if (callInfo) {
                    var now = Date.now();
                    if (now - lastCallTrigger < 2500) return;
                    lastCallTrigger = now;

                    logEvent('call_click', callInfo.phone, 'Clicked: ' + callInfo.label);
                }
            }

            // Listen ONLY to genuine 'click' events with capture phase
            // Note: pointerdown is intentionally omitted to prevent false triggers during mobile scroll, swipe, or pull-to-refresh
            document.addEventListener('click', handleCallEvent, { capture: true });

            // Universal WhatsApp and quote modal click handler
            document.addEventListener('click', function(e) {
                var a = e.target && e.target.closest ? e.target.closest('a') : null;
                if (!a) return;
                var href = a.getAttribute('href') || '';
                if (href.includes('wa.me') || href.includes('whatsapp.com')) {
                    logEvent('whatsapp_click', 'WhatsApp', 'Clicked: ' + (a.innerText || 'WhatsApp Button').trim().substring(0, 50));
                } else if (href.includes('#quote-modal') || (a.classList && (a.classList.contains('spc-btn-free-quote') || a.classList.contains('my-catalog-quote-btn') || a.classList.contains('my-custom-quote-btn')))) {
                    attachFormUrlFields();
                }
            }, true);

        })();
        