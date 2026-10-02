# Contactwiki — User Manual

Contacts for the people and groups in your media - composers, conductors, singers, bands,
orchestras - filled in automatically from Wikidata, Wikipedia and MusicBrainz, and linked from every
album credit. For how it works inside (data shapes, services, the design behind it), see
[`DEVELOPER.md`](DEVELOPER.md).

## What you get

Two kinds of contact alongside contact's own people and businesses:

- **Wiki Individual** - a person: biography (from Wikipedia), dates of birth/death, a photo, role
  tags (Composer, Conductor, Performer...) and links to their Wikidata, MusicBrainz, Discogs, IMDb
  and other pages.
- **Wiki Group** - a band, orchestra, choir or ensemble: the same, with formed/disbanded dates.

Each has a profile page. Every album credit and track artist links to it, and its own page links
back to their music gallery when they have one.

## Setting up

On the admin settings page:

- **Contact address (User-Agent)** - your email or site address. Wikimedia and MusicBrainz ask
  everyone fetching from them to say who they are; without it, long runs get refused sooner.
- **TMDb API Read Access Token** - optional, not needed for music.

## Creating contacts while loading music - the people pass

This is the normal way contacts get made. When you **Process** an artist folder in fisheyemedia's
Add Music Collection, the people pass opens on that artist (or open **Load Wiki People** from the
Contact menu and pick an artist gallery).

It lists everyone credited on the artist's albums - album artists and track artists, including
those inside collections - with a summary such as "20 album folders, 316 tracks, 178 people
credited: 50 already contacts, 128 still to create". Anyone already a contact (from an earlier
artist, say) is counted and skipped. The rest are shown 10 at a time, each with a status:

| Status | Meaning | What to do |
|---|---|---|
| **Create individual / group** | Wikidata has exactly one item for them | Leave ticked |
| **Create from MusicBrainz** | Not on Wikidata - made from MusicBrainz instead; a later Reload picks up Wikidata if it appears | Leave ticked |
| **Wikidata has this MusicBrainz id on more than one item** | A duplicate on Wikidata's side | Pick the right person from the list, then tick |
| **Not resolved** | The lookup couldn't run | Reload the page later |

Press **Create Selected Contacts** (top or bottom) for each batch of 10; the header box ticks or
clears the whole batch. When nobody is left to create, the page moves straight on to loading the
artist's albums (or shows **Continue: Load albums** if anything needs your attention first).

**"The Wikidata lookup failed"** - shown with the reason underneath: *throttled* (wait a minute and
reload), *no response* (network or Wikidata down - try later), or an error from Wikidata itself.
Contacts already held still show as linked.

## Adding one contact by hand

**Add Wiki Individual** / **Add Wiki Group** (Contact menu): paste a Wikidata id or URL (`Q255`,
`https://www.wikidata.org/wiki/Q255`) or a MusicBrainz artist id/URL - it's turned into the
Wikidata id for you. Check the fetched details, untick any suggested role tag that's wrong, and
save.

## Keeping contacts up to date

On a contact's edit page:

- **Reload from Wikidata** - fetches everything again: biography, dates, photo, links, role tags.
- **Reload from MusicBrainz** - for a contact made from MusicBrainz; it checks Wikidata first and
  switches over once Wikidata has the person.

Anything can also be corrected by hand in the contact's detail tabs.

## Finding contacts

- **Wiki Contacts** (Contact menu) lists wiki individuals and groups, with their types, music
  gallery and a summary (dates, Wikidata and MusicBrainz ids).
- The **type filter** above the list has one row for individuals and one for groups: tick the types
  you want (Composer, Conductor, Orchestra...); a row's **All** box ticks or clears that row. Nothing
  ticked shows everyone.
- Contact's own **View Contacts List** shows the same summary for wiki rows.

## Role tags

Individuals: Actor, Director, Composer, Artist, Arranger, Performer, Writer, Conductor. Groups:
Band, Orchestra, Choir, Ensemble, Production Company, Record Label. They're suggested from Wikidata
(occupation, or what kind of group it is) or MusicBrainz, and decide where a person appears in an
album's credits - a Conductor tag puts them under Conductor, an Orchestra under Orchestra.

## Older: Load Wiki Artist Contacts

Surveys every artist gallery for a linked contact and offers to create the missing ones from their
albums' MusicBrainz artist. The people pass covers this and more (everyone credited, not just the
gallery's own artist), so it's rarely needed now.
