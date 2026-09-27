# Social network icons

Brand marks shown beside social links on the Settings page (`App\Support\SocialPlatform`),
served by the `cms` Blade Icons set registered in `App\Providers\CmsServiceProvider`
(`cms-social.instagram`, …). Local files, because the panel references no external host.

Path data is from [Simple Icons](https://simpleicons.org), released under
[CC0 1.0](https://github.com/simple-icons/simple-icons/blob/develop/LICENSE.md) (public
domain). The marks themselves remain trademarks of their owners.

Changes from the upstream files: `<title>` and `role` removed, `fill="currentColor"` added
so the icon takes the panel's text colour. `viewBox` is the upstream `0 0 24 24`.

Platforms Simple Icons does not carry (LinkedIn, Eitaa, Bale, Rubika) use a Heroicon
instead; see `SocialPlatform::icon()`. To add a platform, fetch
`https://raw.githubusercontent.com/simple-icons/simple-icons/develop/icons/<slug>.svg`, apply
the same edits, save it here as `<case value>.svg`, and add the case.
