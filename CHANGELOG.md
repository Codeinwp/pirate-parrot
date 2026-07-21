
 ### v1.4.0 - 2026-07-21 
 **Changes:** 
 - New: read-only Agent Token minted alongside the parrot account, shared with support so automated systems can fetch site diagnostics
 - New: REST API under `pirate-parrot/v1` (`/manifest`, `/site`, `/products/{slug}`, `/logs`) protected by the Agent Token, with rate limiting, secret redaction and payload size caps
 - New: `pirate_parrot_register_diagnostics` filter so Themeisle products can register their own lazy diagnostics providers
 - New: agent access log displayed on the Support Parrot admin page
 - Improvement: Agent Token stored hashed and displayed only right after generation; both credentials share the 5-day expiry and the Release parrot kill switch
 - Improvement: log capture stays active for the whole grant window instead of only while the parrot user is logged in
 - Fix: tokens are now generated with a cryptographically secure source
 - Fix: logging hooks were registered for every logged-in user due to a leftover debug flag
  
 ### v1.3.0 - 2017-08-08 
 **Changes:** 
  
