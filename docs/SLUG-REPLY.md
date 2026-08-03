# Reply to the WordPress.org automated slug email

**Send this the moment the automated email arrives.** The slug can be changed while a
plugin is in review, but **not after approval** — so this must be settled in writing before
the reviewer approves.

Reply directly to the automated email (keep the thread and its subject line, so the plugin
review team can match it to your submission).

---

## Paste-ready reply

> Subject: Re: [WordPress Plugin Directory] Plugin Submission — WP Command Center
>
> Hello,
>
> Thank you for the confirmation. Could the plugin slug be set to:
>
> **ai-command-center**
>
> rather than the auto-derived `wp-command-center`?
>
> The submitted package is already built entirely around that slug — the ZIP's top-level
> folder, the plugin directory, the main plugin file (`ai-command-center.php`) and the text
> domain (`ai-command-center`) all use it. Matching the slug keeps the text domain valid so
> translations and language packs load correctly.
>
> As I understand it the slug is derived automatically from the `Plugin Name:` header, which
> in our case reads "WP Command Center" — the Plugin Developer FAQ notes that the automated
> email's slug is populated from that header. The display name is intentional and we would
> like to keep it as **WP Command Center**, with `ai-command-center` as the directory slug.
>
> I am raising this now rather than after review, since I understand slugs cannot be changed
> once a plugin is approved.
>
> Please let me know if you need anything else from me.
>
> Thank you for your time reviewing the submission.
>
> Best regards,
> Mosharaf Hossain

---

## Notes for the sender

- **Requested slug:** `ai-command-center`
- **Public plugin name (unchanged):** `WP Command Center`
- **Why it is safe to ask:** the slug is auto-derived from the `Plugin Name:` header; the
  Plugin Developer FAQ states the automated email's slug comes from that header. Requesting
  a different slug at submission time is a normal, supported request.
- **Why it is urgent:** *Make WordPress Plugins* — "Reminder: We can't rename plugins post
  approval" — the slug is permanent once approved.
- **Do not** change the `Plugin Name:` header to force the slug. That would alter the public
  product name, which is a frozen decision (RELEASE_HANDOFF §4.1).

### If the reviewer instead asks you to change the *name*

Do not decide on the spot. Per RELEASE_HANDOFF §9.3 the naming decision unfreezes **only**
on an explicit reviewer request, and the owner picks the new name. Report the request, agree
a name, then change it in all four version/name locations and re-run the §9.5 verification
before resubmitting.
