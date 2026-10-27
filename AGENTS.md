# Project collaboration preferences

- Deliver generated files and handoff bundles through this GitHub repository.
  The user cannot download workspace attachments from chat; do not offer those links.
- Keep reviewed handoff artifacts under `handover/releases/`, with a README and
  checksum. Commit and push them before providing GitHub links to the user.
- Use ordinary ZIP archives without a password unless the user asks otherwise.
- Keep the repository private. Never include connection configs, passwords,
  private keys, customer data, database dumps or runtime cache in release bundles.
- Shop changes remain restricted to the test installation. Igor performs the
  reviewed production transfer manually; preparing a bundle does not deploy it.
