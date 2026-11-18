/**
 * MlSignup – posts the signup form to the site's own endpoint.
 * Spam protection: a small proof-of-work (SHA-256) is solved in the browser
 * before submitting. No third-party requests are made.
 */
(() => {
    'use strict';

    const enc = new TextEncoder();
    const hex = (buf) => Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');

    const postForm = async (url, data) => {
        const res = await fetch(url, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json' },
            credentials: 'omit',
            cache: 'no-store'
        });
        let json = null;
        try { json = await res.json(); } catch (e) { /* ignore */ }
        return { ok: res.ok, json };
    };

    const solve = async (ch) => {
        for (let n = 0; n <= ch.maxnumber; n++) {
            const digest = await crypto.subtle.digest('SHA-256', enc.encode(ch.salt + n));
            if (hex(digest) === ch.challenge) {
                return n;
            }
        }
        throw new Error('challenge not solvable');
    };

    const init = (form) => {
        const status = form.querySelector('.mlsignup__status');
        const button = form.querySelector('button[type="submit"]');
        const show = (state, text) => {
            status.dataset.state = state;
            status.textContent = text;
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            if (!window.crypto || !crypto.subtle) {
                show('error', form.dataset.msgError);
                return;
            }

            button.disabled = true;
            show('', form.dataset.msgWorking);

            try {
                const c = new FormData();
                c.append('mlsignup', 'challenge');
                const chRes = await postForm(form.action, c);
                if (!chRes.ok || !chRes.json || !chRes.json.challenge) {
                    throw new Error('challenge request failed');
                }

                const ch = chRes.json;
                const number = await solve(ch);
                const payload = btoa(JSON.stringify({
                    algorithm: ch.algorithm,
                    challenge: ch.challenge,
                    salt: ch.salt,
                    signature: ch.signature,
                    number
                }));

                const data = new FormData(form);
                data.set('mlsignup', 'subscribe');
                data.set('payload', payload);

                const res = await postForm(form.action, data);
                const msg = res.json && res.json.message ? res.json.message : form.dataset.msgError;

                if (res.ok && res.json && res.json.success) {
                    show('ok', msg);
                    form.querySelectorAll('.mlsignup__field, .mlsignup__consent, .mlsignup__hint, button').forEach((el) => {
                        el.hidden = true;
                    });
                    return;
                }
                show('error', msg);
            } catch (e) {
                show('error', form.dataset.msgError);
            }
            button.disabled = false;
        });
    };

    document.querySelectorAll('form.mlsignup__form').forEach(init);
})();
