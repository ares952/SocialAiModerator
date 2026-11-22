# Bezpečnostní hlavičky

Podle specifikace §8.2 a milníku 5. Hlavičky HTML odpovědí posílá Nette (`http:` v
`config/common.neon`), `X-Content-Type-Options` přidává nginx všem odpovědím včetně statických
souborů (`deploy/nginx/conf.d/default.conf`).

| Hlavička | Hodnota | Proč |
| --- | --- | --- |
| `Content-Security-Policy` | `default-src 'none'`, skripty a styly jen z `'self'` (+ nonce), obrázky `'self'` a `data:`, `form-action 'self'`, `frame-ancestors 'none'`, `base-uri 'none'` | zmírní XSS i při chybě escapování: cizí ani vložený skript se nespustí |
| `X-Frame-Options` | `DENY` | administraci nejde vložit do rámu (clickjacking); totéž `frame-ancestors` |
| `X-Content-Type-Options` | `nosniff` | prohlížeč nehádá typ souboru |
| `Referrer-Policy` | `same-origin` | adresy administrace (ID událostí, filtry) neodcházejí na cizí weby |
| `Permissions-Policy` | kamera, mikrofon, poloha, platby, USB vypnuté | administrace je nepotřebuje |
| `Cross-Origin-Opener-Policy` | `same-origin` | izolace okna od cizích stránek |

`X-Powered-By` se neposílá (Nette i PHP `expose_php = Off`). **HSTS** nastavuje reverzní proxy,
která ukončuje TLS; aplikace za ní HTTPS nevidí. Stránka údržby má vlastní přísnou CSP v nginx.

## Pravidla pro vývoj

- Žádné inline skripty (`<script>…</script>`, `onclick=`) ani atributy `style=`. Chování patří do
  `www/assets/admin/*.js`, vzhled do tříd v `admin.css`; data pro skripty do `data-*` atributů
  (grafy: `data-chart`). Nastavování stylů z JavaScriptu přes `element.style` CSP nevadí.
- Žádné zdroje z cizích domén (CDN, fonty, obrázky); knihovny jsou lokální kopie
  (`docs/admin-ui.md`).
- Nonce v CSP slouží jen panelu Tracy v debug režimu (`APP_DEBUG=1`). Vlastní kód ho nepoužívá.
- Porušení CSP se ukáže v konzoli prohlížeče („Refused to …“); `bin/e2e` hlídá, že hlavičky
  odcházejí.

## Ověření na instanci

```sh
docker compose exec -T web wget -S -qO /dev/null http://127.0.0.1/sign/in 2>&1 | grep -i 'content-security\|x-frame\|nosniff\|referrer'
```
