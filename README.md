# Contactwiki

A [Bitweaver](https://github.com/lsces/bitweaver) package extending
[contact](https://github.com/lsces/contact)'s person/business records with two Wikidata-backed
content types — a Wiki Individual and a Wiki Group — that fetch and keep their own biography,
external-identity links, and key dates from [Wikidata](https://www.wikidata.org) (and, via a
MusicBrainz artist id, from [MusicBrainz](https://musicbrainz.org) too).

## Why this exists

A media package that credits a person currently has nowhere durable to put that credit — a
discography's own `artist`/`composer`/`conductor` fields, for instance, store a bare name string,
with no shared identity, bio, or external links across every work that same person appears on.
Contact's own `ContactPerson`/`ContactBusiness` records are the natural home for that shared
identity, but hand-typing a biography and a set of external-id links for every artist in an
existing library doesn't scale. This package automates that: given a Wikidata id (or a MusicBrainz
artist id, which usually resolves to one automatically), it creates or refreshes a Contact record
with everything Wikidata/Wikipedia can supply.

## What it does

- **Add a Wiki Individual or Wiki Group** from a Wikidata id/URL, or a MusicBrainz artist id/URL —
  fetches the entity, pre-fills the add form (name, suggested role tags, external-identity links,
  key dates, a downloaded profile image, and a Wikipedia-sourced biography), and lets you review
  before saving
- **Reload from Wikidata** on an already-created record — re-runs the same fetch-then-apply pass to
  pick up anything changed on Wikidata since
- **People pass for one artist/composer gallery** (`load_wiki_people.php`) - lists everyone
  credited across its albums, matched against existing contacts, Wikidata and MusicBrainz, and
  creates them in batches; part of fisheyemedia's one-folder music loading
- **Batch survey of an existing music library** (`load_wiki_artists.php`) — scans every top-level
  artist/composer gallery in a [fisheye](https://github.com/lsces/fisheye)-backed music collection,
  shows which already have a linked Contact, and for the rest, resolves a MusicBrainz artist id
  already captured on the imported tracks to find a Wikidata match — nothing is created until the
  list is reviewed and a batch submitted
- **Role-tag suggestions** (Actor/Director/Composer/Artist/Arranger/Performer for an individual;
  Band/Orchestra/Choir/Ensemble/Production Company/Record Label for a group) derived from Wikidata's
  own occupation/instance-of claims, shown as pre-ticked checkboxes rather than applied silently

See [`MANUAL.md`](MANUAL.md) for how to use it, and [`DEVELOPER.md`](DEVELOPER.md) for the design -
the "Contact as a universal person/entity hub" idea this package implements, the Wikidata property
mapping, the services it provides - and what isn't built yet.

## What's planned

- Retrofitting Film/TV cast and crew credits (currently plain text on `fisheyefilm`/
  `fisheyeprogram`) to reference a Contact the same way a music credit will
- Linking a group's own Wikidata band-member claims to create/link the individual members' own
  Contact records
- A `details` item for place-of-birth/place-of-death, currently unfetched (only DOB/DOD - Wikidata
  time-valued claims - are captured today, mirrored onto `liberty_content.event_time` for sort/list)

## Requirements

- [Bitweaver](https://github.com/lsces/bitweaver) 5.x
- [`contact`](https://github.com/lsces/contact) package, active — this package extends its
  `ContactPerson`/`ContactBusiness` content types and reuses its generic xref framework
- [`liberty`](https://github.com/lsces/liberty) package — the underlying generic content/xref
  framework both packages are built on
- The batch survey (`load_wiki_artists.php`) additionally needs [`fisheye`](https://github.com/lsces/fisheye)
  active with an already-imported music library carrying MusicBrainz artist tags — the add-flow
  pages work without it
- No API key required for Wikidata/Wikipedia; an optional contact string for these APIs' own
  etiquette requirements, and an optional TMDb token for a person's biography fallback, are both
  configured on this package's own admin settings page

Since this package isn't through a stable install/upgrade cycle yet, see `DEVELOPER.md` in this
repo for the current schema-deployment approach if you're installing it fresh.
