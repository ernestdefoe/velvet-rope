The first release of Velvet Rope: turn readers into members on Flarum 2.

![A guest reading a discussion: the first post in full, its image locked, each reply held back with a sign-up prompt](https://raw.githubusercontent.com/ernestdefoe/velvet-rope/1.0.0/screenshots/discussion.png)

**Free discussions for guests.** Give guests an allowance per day, week or month. Discussions they've opened stay free for the rest of the period. After that they see the start of the first post and a prompt to sign up, with a notice beforehand when only a few are left. Tags can be left out of the count. An optional per-address count catches visitors who clear or refuse cookies.

**Rules, per tag and per group.** Each rule says which groups read everything in its tags. Everyone else reads the first posts and either a preview of the rest or none of it, and you can lock images, attachments and embeds in the posts they do read. Drag rules into order. One forum can let guests read every first post and keep a whole tag for a supporters group, with an upgrade link for members outside it.

![A members-only tag, seen by a member outside its group: a preview and an upgrade prompt](https://raw.githubusercontent.com/ernestdefoe/velvet-rope/1.0.0/screenshots/supporters.png)

**The right prompt for each reader.** Guests get sign up and log in. Unconfirmed accounts can resend their confirmation email. Members get your upgrade page.

**Held back on the server.** Wherever Flarum renders a post, gated content never reaches the page source, the API, search, mention previews, notification email, FoF SEO's structured data or Realtime pushes. Extensions that read a post's raw text themselves can ask Velvet Rope first (see the README).

**SEO-safe.** Verified search engines (proved by DNS, not by user agent) read everything, marked with the paywall structured data Google asks for (`isAccessibleForFree`), so it isn't cloaking. This works with FoF SEO.

**Live preview** of every rule and of the meter in the admin page.

Links are never touched. To hide them from guests, pair Velvet Rope with [Guest CTA](https://github.com/ram0ng1/guest-cta).

Free and MIT licensed.

```sh
composer require ernestdefoe/velvet-rope
php flarum migrate
php flarum cache:clear
```
