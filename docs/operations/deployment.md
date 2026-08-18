# Deployment

Closes issue #06, which was blocked on a hosting decision. The decision is
below, with the reasoning, so it can be argued with rather than inherited.

---

## The host: Cloudways

**Chosen on the requirement list in `CLAUDE.md` #06:** staging, SSH, WP-CLI,
Redis, daily backups, PHP 8.2+.

Redis is what decides it. It is the requirement most hosts either omit or
charge separately for, and the difference is not marginal:

| Host | Staging | SSH + WP-CLI | Redis | Daily backups | Roughly |
|---|---|---|---|---|---|
| **Cloudways** | yes | yes | **included** | yes | **$28/mo** (2GB) |
| Kinsta | yes | yes | **paid add-on, ~$100/mo** | yes | $35/mo + add-on |
| WP Engine | yes | yes | higher tiers only | yes | $20–30/mo |
| SiteGround | higher tiers | limited | higher tiers | yes | $15–30/mo |

Kinsta is the better host in most other respects and has a Tel Aviv region on
Google Cloud, which genuinely matters for Israeli latency. It loses here purely
on Redis pricing: an add-on costing three times the plan makes a documented
requirement into one that gets quietly skipped, and a requirement everybody
routes around is worse than one nobody wrote down.

**One thing I could not verify.** I intended to check whether Cloudways offers
an Israel region through Vultr, since that was the tie-breaker against Kinsta.
Both vultr.com and Cloudways' support site are behind bot protection, and
working around that is not something to do on the client's behalf. The region
is a dropdown at signup — **pick Tel Aviv if it is offered, otherwise Frankfurt
or Amsterdam**, which are the closest to Israel at roughly 60–70ms.

Start on the **2GB** plan. WordPress with WooCommerce and Redis on 1GB is tight
enough that the first plausible traffic spike becomes a support ticket.

---

## Three things only you can do

I can build and run the deployment. I cannot do these, and should not:

1. **Create the Cloudways account.** Creating accounts is not something I do on
   your behalf.
2. **Enter payment details.** Likewise, and for better reasons.
3. **Add the GitHub secrets** listed below — they include a private key.

**Open the account in Eli's name, not yours.** The repository is already on
your personal account, contrary to the client-owns-assets principle, and
`CLAUDE.md` flags it as unresolved before launch. Do not repeat the pattern
with hosting and the domain: they are much harder to unwind later, and harder
still once Cortez money is part of the conversation.

---

## Setting it up

### 1. On Cloudways

Create a server, then an application:

- **Application:** WordPress (not "WordPress + WooCommerce"; the provisioning
  script installs and configures WooCommerce itself, so its settings are in
  version control rather than clicked in once and forgotten)
- **Server size:** 2GB
- **Region:** Tel Aviv if offered, otherwise Frankfurt or Amsterdam
- Enable **Redis** from the add-ons panel
- Confirm **daily backups** are on

### 2. SSH key

Generate a key that exists only for deploying:

```bash
ssh-keygen -t ed25519 -C "electricchic-deploy" -f ~/.ssh/electricchic_deploy -N ""
```

Add the **public** half (`~/.ssh/electricchic_deploy.pub`) to the Cloudways
application's SSH keys. The private half goes into GitHub in the next step and
nowhere else — never into the repository, which is public.

### 3. GitHub configuration

**Settings → Secrets and variables → Actions.**

Secrets (encrypted):

| Name | Value |
|---|---|
| `SSH_KEY` | Contents of `~/.ssh/electricchic_deploy` — the private key |

Variables (not secret, and visible in logs):

| Name | Example |
|---|---|
| `SSH_HOST` | `123.45.67.89` |
| `SSH_USER` | Cloudways application username |
| `SSH_PORT` | `22` |
| `REMOTE_PATH` | `/home/master/applications/xxxx/public_html` |
| `SITE_URL` | `https://demo.electricchic.co.il` |

### 4. First deploy

Actions → **Deploy** → Run workflow → tick **provision**.

That runs `scripts/provision-remote.sh` on the host: installs WooCommerce,
**enables HPOS while zero orders exist**, activates the theme and plugin, sets
currency to ILS and the timezone to Asia/Jerusalem, and rebuilds the catalogue
and homepage from the repository.

Afterwards, deploys are automatic: merge to `main`, CI goes green, the deploy
runs. Tick **provision** again only when content should be rebuilt from scratch
— it replaces every product.

---

## How this deploys

**Only two directories ship**: `electricchic-core` and `electricchic-child`.
WordPress core, WooCommerce, uploads and the database belong to the host and
are never overwritten from here. `rsync --delete` is scoped to those two paths
so it cannot reach anything else.

**No database dump.** The demo rebuilds from the repository — the catalogue is
a JSON file, the homepage is a script. A dump would carry whatever happened to
be in a local database: test orders, a developer's email, a password hash. This
carries only what has been through review, which is also why staging and
production cannot quietly drift from what the repository says they are.

**The deploy waits for CI.** It triggers on the CI workflow completing on
`main` and checks the conclusion, so a red build cannot deploy.

**It is not finished when rsync exits zero.** `verify-deployment.sh` runs
against the live URL afterwards and fails the deploy if the site is wrong — see
below.

---

## Verification

```bash
./scripts/verify-deployment.sh https://your-site
```

Thirteen checks over HTTP, the way a visitor and a scraper reach the site.
Reading a setting proves the setting; a cache, a CDN or a stale opcache all sit
between that and the page.

Every check attempts a violation rather than inspecting a value:

- The demo banner is on the page, and `noindex` is actually served
- Badges render, states vary, and no card has a duplicate badge
- **No supplier or cost field** appears in the REST API, the Store API, or the
  page source
- A **non-purchasable product is refused** through both the `?add-to-cart=` URL
  and the Store API
- A **purchasable product is still accepted** — the control, without which a
  site that refused everything would look identical to a working guard

That last pair matters. The Store API check originally posted without a nonce
and read the resulting 401 as a refusal; every request 401s without a nonce, so
it reported the guard proven while never exercising it.

---

## Demo mode

The site defaults to demo mode: banner, `noindex`, and both checkout paths
refusing orders. Nothing needs configuring for that — it is on unless turned
off.

Going live is one line in `wp-config.php`:

```php
define( 'EC_DEMO_MODE', false );
```

Do not add it until prices are confirmed with Eli, image rights are resolved,
and payment is connected. Everything is `_ec_image_rights = pending` today.

---

## Rolling back

Deploys are a file sync, so the previous state is the previous commit:

```bash
git revert <sha> && git push
```

CI runs, the deploy follows, verification confirms it. For a content mistake,
re-run the workflow with **provision** ticked — the catalogue rebuilds from
`scripts/data/cortez-catalogue.json`.

Database rollback is Cloudways' daily backup, restored from their panel. There
is deliberately no database restore in this pipeline: an automated restore that
runs at the wrong moment destroys real orders.

---

## Not covered yet

- **Staging.** Cloudways gives it free; wire it up once the demo is settled, by
  pointing a second set of variables at the staging application.
- **A domain.** The Cloudways URL works for the demo. A real domain is Eli's to
  buy, in his name.
- **Payment.** Blocked on knowing the actual acquirer — see the WhatsApp draft
  in `docs/whatsapp-to-eli.md`. יציל is credit-card factoring, not a gateway.
