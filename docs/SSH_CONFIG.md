# Server Access / SSH Configuration

> Required by the server slash commands (`/deploy`, `/rollback`, `/monitor`, `/logs`, `/status`, `/db`, `/test-live`). Each begins with `ssh [host-alias] "echo connected"` and STOPS if it fails. **SSH is not configured in this repo yet** — set it up below.

## Known facts (from `docs/CODEBASE_AUDIT.md`)
- Production API host: `api.easycoders.in` (Apache; Laravel under `/projects/orvell/public/`).
- Production DB: MySQL, user `orvell_user`.
- A droplet hostname `ubuntu-s-2vcpu-8gb-amd-blr1-01` appears in a stray committed artifact (`ql -u orvell_user -p` in the backend — should be deleted).
- No SSH config, server IP, or firewall rules exist in the codebase.

## Define a host alias
The commands use a placeholder `[host-alias]`. Add a real alias to `~/.ssh/config`:

    Host orvell-prod
        HostName <SERVER_IP_OR_DOMAIN>
        User <deploy-user>          # dedicated non-root user (e.g. claude-server), NOT root
        IdentityFile ~/.ssh/orvell_deploy
        IdentitiesOnly yes

Then use `orvell-prod` wherever a command says `[host-alias]`.

## Security guidance (see `/security` AUDIT 7)
- Use a dedicated deploy user, never `root`.
- Use a separate key for automation; don't reuse personal keys.
- Restrict SSH to known IPs at the firewall.
- Revoke keys when a team member leaves.
- Obtain the real server IP/credentials from the repo owner out-of-band — they are NOT in the codebase.
