# Contactwiki — Developer Reference

Wiki-sourced contacts: individuals and groups whose identity, biography, dates, image and external
ids come from Wikidata (or MusicBrainz where Wikidata has no item), used as the one shared identity
every media credit links to. The first half is the design reasoning, which still holds; the second
half is how the package works today. For using it, see [`MANUAL.md`](MANUAL.md). Read alongside `contact/MANUAL.md` (the base
package this extends) and `liberty/MANUAL.md` (the xref machinery it builds on - no schema of its
own beyond its two content types).

## The problem this solves

Without a shared identity, a media package credits a person as a bare text string - a name in
`xkey_ext`. That means the same real person (say, a conductor who appears on dozens of unrelated albums) has no single identity anywhere
in the system — no shared bio, no shared external links (MusicBrainz/Discogs/Wikidata), and no way
to ask "what does this system know about, or featuring, this person" as one query. Film/TV cast and
crew credits, whenever built, would hit the identical limitation independently unless designed
against a shared answer from the start.

## Core idea

**Contact is the master record.** Any named person or ensemble referenced by any media package —
music artist/composer/conductor/orchestra/performer today, film/TV actor/director/writer as a
planned retrofit — gets (or reuses) exactly one `Contact` (`ContactPerson` for an individual,
`ContactBusiness` for a group/orchestra/ensemble). Everything else — a discography gallery, a film
credit, a bio, external links — hangs off that one record. The Contact is durable and
package-agnostic; media objects come and go around it.

Two things do **not** get merged into one object:

- **A Contact is not a Gallery, and a Gallery is not a Contact.** `content_type_guid` is a single,
  exclusive value per `liberty_content` row — it drives `getLibertyObject()`'s dispatch,
  permission checks, and `liberty_xref_item` scoping (item names are already joined against
  `content_type_guid` specifically because the same item name means different things on different
  content types). A hybrid row that's simultaneously `fisheyegallery` and `contact` isn't a
  simplification, it's structurally incompatible with how the rest of Liberty already works — and
  it would trap a person's bio/external-links inside fisheye specifically, unreachable from a
  completely different package's own credit xrefs. That defeats the entire point.
- **Not every Contact needs a Gallery.** A discography gallery only exists when there's a literal
  `Music/<Artist>/` folder on disk backing it. An ensemble credited as a performer on dozens of
  other artists' albums but with no folder of its own is a perfectly valid Contact with no gallery
  link at all — that isn't a gap to fill later, it's the expected shape for that kind of entity.

## Existing seed: the `WPxx`/`WBxx` role markers

`contactperson`'s `type` xref group carries a set of person-role markers, at `sort_order=0`
(Liberty's toggleable-multi-tag convention — see `contact/MANUAL.md`'s own `P01`/`P02` for the
pattern), distinct from the personal/business-capacity `P01`/`P02` markers. `contactbusiness`
carries the equivalent group/ensemble-role markers:

| Item | Role |
|---|---|
| `WP01` | Actor |
| `WP02` | Director |
| `WP03` | Composer |
| `WP04` | Artist |
| `WP05` | Arranger |
| `WP06` | Performer |
| `WP07` | Writer |
| `WP08` | Conductor |

| Item | Role |
|---|---|
| `WB01` | Band |
| `WB02` | Orchestra |
| `WB03` | Choir |
| `WB04` | Ensemble |
| `WB05` | Production Company |
| `WB06` | Record Label |

They answer "what kind of media-credited person is this": set automatically when a contact is
created or reloaded (see Role tags below), editable by hand, and read by fisheyemedia to put each
album credit under the right job.

## The linking mechanism — already exists, no new liberty schema needed

`liberty_xref` has a dedicated `xref` column (`BIGINT`), entirely separate from the string-value
`xkey`/`xkey_ext` columns — it exists specifically so one xref row can point at *another*
`content_id` instead of storing a literal value. This isn't a new idea: `stock`'s own supplier
link already uses exactly this shape —

- `stockcomponent`'s `sup` xref item stores the Contact's `content_id` in `xref`
- `stock/templates/xref/stockcomponent/view_sup_item.tpl` renders it as:
  `<a href="{$smarty.const.CONTACT_PKG_URL}view.php?content_id={$xrefInfo.xref}">{$xrefInfo.linked_title}</a>`

Every "this links to a person" xref item this design adds reuses that same reference-style
template shape — never `template='text'`.

## Structure

- **Contact → discography gallery** (optional): a `music_gallery` xref item, `content_type_guid`
  scoped to `contact` (the shared base level `contact/MANUAL.md` already registers SCREF/addresses
  at, not `contactperson`/`contactbusiness` specifically — a linked identity could be either),
  `xref` = the gallery's `content_id`. Set only when a real folder-backed gallery exists for that
  Contact.
- **Media credit → Contact** (the actual workhorse link): each work's own credit xref -
  `fisheyealbum`'s `artist`/`composer`/`conductor`/`orchestra`/`performer` and its per-track
  `track`/`track_artist` rows, later a `fisheyefilm`/`fisheyeprogram` actor/director/writer
  equivalent - stores `xref` = the Contact's `content_id` directly, `xkey` = its Wikidata Q-id (or
  Discogs artist id when it has none). **Not routed through the gallery** — a credit on a Film should never need
  to know whether that person happens to also have a Music gallery.
- **Bio/description**: plain `lc.data` on the Contact record itself. No new xref needed — this is
  already Contact's own free-text "note" field, already shown by `show_contact.tpl` above the
  address block.
- **The `contact:external` xref group** (`x_group='external'`, `content_type_guid='contact'`,
  defined by the site's own contact xref scheme): outbound identity links - `wikidata`,
  `musicbrainz`, `discogs_artist`, `imdb`, `tmdb`, `viaf`, `official_site`... - each an
  `href`-template item with `cross_ref_href` set. The same shape as `fisheyealbum`'s own
  `mbid`/Discogs links, held once on the shared identity instead of per album.

## "Everything by/featuring this Contact" — falls out for free

No new mechanism needed for this — it's an inherent reverse query once credits store `xref`
instead of a string:

```sql
SELECT lx.content_id, lx.item, lc.title, lc.content_type_guid
FROM liberty_xref lx
INNER JOIN liberty_content lc ON lc.content_id = lx.content_id
WHERE lx.xref = ?   -- the Contact's content_id
```

This naturally spans every content type at once — an ensemble's own "appears on" listing is just
this query, with no gallery, no per-package plumbing, and no special-casing for the
no-gallery-of-their-own case.

## External sources — what each actually provides

Contact serves every media type, so this splits by medium — music, film/TV, books/authors — plus
one source that's genuinely medium-agnostic. The shape of the problem repeats in each block: the
"official" metadata database for the medium gives structured identity but is thin or absent on
actual prose biography, so a real bio needs a second, often community-run source keyed off the
same id. It also repeats sideways: a person can legitimately belong to more than one block at once
(a composer scoring films, an author whose novel gets adapted) — `contact:external` items coexist
on one Contact rather than forcing a single source per person.

**Music** — MusicBrainz gives structured identity but **no prose biography** at all, a deliberate MB
project policy, not a gap in the API:

| Source | What it gives | Notes |
|---|---|---|
| **MusicBrainz Artist** (`/ws/2/artist/<mbid>`) | `name`, `sort-name`, `disambiguation`, `type` (Person/Group/Orchestra/Choir/...), `gender`, `country`/`area`, `begin-area`/`end-area`, `life-span`, `aliases[]`, `ipis[]`/`isnis[]`, `tags[]`/`genres[]`, `rating`; via `inc=url-rels`: links to Wikidata, Discogs, official homepage, social accounts, IMDb, etc. | No biography field. The MBID is already captured today via `FISHEYEALBUM_COMMON_TAG_MAP`'s `MUSICBRAINZ_ALBUMID` handling, so it's the natural join key for everything below. |
| **TheAudioDB** (`theaudiodb.com/api/v1/json/2/artist-mb.php?i=<mbid>`) | `strBiographyEN` (+ other languages), formed year, genre/style/mood, thumb/fanart/banner images | Keyed directly off the MBID — a single hop, no name-matching ambiguity. Likely the closest match to what Plex's own music agent shows as "About the Artist". Best first choice for real bio prose. |
| **Discogs artist profile** | `profile` field — prose bio | `FisheyeAlbum::fetchDiscogsLink()` already exists for album-level Discogs data, so some of this plumbing is reusable. Good fallback when TheAudioDB has nothing for an artist. |

**TMDb does not generally cover the music space** — its own person catalogue is scoped to people
with an actual film/TV credit, not musicians broadly. A working artist with no documentary, concert
film, biopic, or scoring credit simply has no TMDb entry at all, so it can't stand in for
MusicBrainz/TheAudioDB/Discogs as a general music source. The overlap is real but narrower than it
looks: a composer who also scores films, or an artist who's the subject of a concert film or
documentary, genuinely does get a TMDb person page — for exactly that person, a Contact can (and
should) carry *both* an `mbid` and a `tmdb_id` at once, since these are separate `contact:external`
items on the same record, not a choice between one or the other. TMDb is worth checking as a
supplementary link whenever that overlap exists, just not assumed as a first-choice music source.

**Film/TV** — the same shape again, and closer to being ready than it looks: `imdb`/`tmdb`/`tvdb`
are already captured today as plain external-link xref items on `fisheyefilm`/`fisheyeprogram`
(pulled straight from Plex's own metadata GUIDs, `<Guid id="imdb://...">`/`<Guid id="tmdb://...">`)
— currently just stored as link IDs, never used to actually fetch person data, exactly like
music's own `mbid`/`discogs` items before this design.

| Source | What it gives | Notes |
|---|---|---|
| **TMDb** (`/3/person/<tmdb_id>`) | `biography` (real prose — TMDb, unlike MusicBrainz, does host bios directly), `birthday`/`deathday`, `place_of_birth`, `also_known_as[]` (aliases), `profile_path` (photo), `known_for_department`, `gender`, `popularity`; `append_to_response=external_ids` in the same call returns `imdb_id`/`tvdb_id`/`wikidata_id`/`instagram_id`/`twitter_id`/`facebook_id` for free. | Free, actively maintained, generous rate limits — the modern, practical first choice for a real bio, and the natural next step here since the `tmdb` id is already being captured, just not fetched from yet. |
| **TheTVDB** (`/v4/people/<tvdb_id>`) | Name, image, birth date/place, some biography text via translations | Its own person endpoint exists but is comparatively sparse on biography compared to TMDb — TVDB is much stronger on show/season/episode metadata than on cast/crew prose. Useful as a fallback or for a person TMDb hasn't matched, not a first choice. |
| **IMDb** | — | **No accessible API for actual data** — IMDb's own data is proprietary; programmatic access is a paid/licensed commercial product ("Essential Metadata"), not something a self-hosted app integrates directly. The `imdb` xref item should stay exactly what it is today: an outbound link people can click, never a fetchable data source. |

**Books/Authors** — the fourth media block, not yet built out at all (no `fisheyebook`-equivalent
content type exists today). The same author-also-writes-for-film/TV overlap as composers/musicians
applies here too — a novelist credited as "story by"/"based on characters created by" on a TMDb
crew list is a real, if imperfect, case for the same Contact carrying both an `openlibrary_id` and
a `tmdb_id`:

| Source | What it gives | Notes |
|---|---|---|
| **Open Library** (`openlibrary.org/authors/<id>.json`) | `name`, `bio` (often itself Wikipedia-sourced but presented as clean structured text), `birth_date`/`death_date`, `alternate_names[]`, `photos[]` (via Internet Archive's cover service), `links[]` (including a Wikipedia URL when known); `/authors/<id>/works.json` gives the author's own bibliography for free. | Free, keyless, run by the Internet Archive — the closest thing to "MusicBrainz for books" in spirit, and the practical first choice here. Bonus: bibliography data comes from the same source, no separate lookup needed. |
| **VIAF** (Virtual International Authority File) | Aggregated library-catalogue authority records (Library of Congress, British Library, etc.) — confirms which "John Smith" is meant, links out to national library IDs | Not a bio source itself, but the disambiguation-of-identity role MBIDs play for musicians — worth using to confirm a match before trusting a bio fetched elsewhere, not for the bio text itself. |
| **AbeBooks** | Decent author summary text on their own bookshop site | No public API found for this — their author pages are presentation-only on-site content, not something to integrate against the way Open Library's actual JSON API can be. Fine as a manual reference link, not a fetch source. |
| **Goodreads** | (historically: bio, ratings, "similar authors") | Its public API was deprecated years ago (Amazon-owned) and isn't open to new integrations any more — despite being the name most people think of first, not a realistic modern choice. |

**Medium-agnostic fallback** — Wikidata → Wikipedia works identically for a musician, an actor, a
director, or an author, since it doesn't care which domain-specific database first pointed at it —
for authors specifically this hop is often the *more* reliable base, not just a fallback, since
well-known authors tend to have well-curated Wikipedia biographies:

| Source | What it gives | Notes |
|---|---|---|
| **Wikidata → Wikipedia** (via a `url-rels`-style Wikidata link from MusicBrainz, TMDb's `external_ids`, or Open Library's own `links[]`, then Wikipedia's REST summary endpoint) | Lead-paragraph extract | Works, keyless, for any person regardless of medium — a longer chain (two hops) with less control over tone/length than a purpose-built bio field, but for authors it's less of a fallback and more a first-choice-equivalent to Open Library's own `bio` field, which is itself often just Wikipedia text anyway. |

## Open question: band/ensemble membership over time

`ContactBusiness` is the natural fit for a band/orchestra/ensemble conceptually — it's a group, not
a person — and now has its own `WBxx` role markers (above) alongside `contactperson`'s `WPxx`. What
that split doesn't touch: a band isn't just "a `ContactBusiness` with a group label", it's made of
individual persons whose own membership changes over time, and any one of those persons may belong
to several different bands across different periods. Modelling *that* — the actual person↔band
membership relationship — is a real design task on its own, not something to fold into the
person/gallery/credit linking above without thinking it through — deliberately left open rather
than guessed at here.

The one piece already in place for it: `liberty_xref` already carries `start_date`/`end_date` on
every row, so a person↔band membership xref (whichever direction it ends up living on) already has
a mechanism for "member from X to Y" built in — this doesn't need new schema, just a proper pass at
the actual xref shape once it's discussed.

**Deferred idea, not decided**: collapsing `WPxx`/`WBxx`'s separate xref rows into one packed flag
value — see `liberty/MANUAL.md`'s "Type-marker convention" section, its correct home since it's a
generic Liberty idea, not Contact-specific.

## Content types and classes

- `ContactWikiIndividual extends ContactPerson` - `content_type_guid='contactwikiindi'`.
- `ContactWikiGroup extends ContactBusiness` - `content_type_guid='contactwikigroup'`; a group's
  name is plain `organisation`/`liberty_content.title`.

Both share `ContactWikiTrait` (a trait, since their real parents differ) for every fetch/apply
helper. Each overrides `storeXref()` to mirror its main date xref (`dob` for an individual,
`formed` for a group) into `liberty_content.event_time`, so lists sort by it. `getDisplayUrl()`/
`getEditUrl()` point at this package's own `view.php` (`view_wiki_profile.tpl`, panels read straight
from the xref groups, so a new item just appears) and `edit_wiki_indi.php`/`edit_wiki_group.php` -
anything linking to a contact never needs to know which package owns its pages. Single contacts can
also be added by hand: `add_wiki_person.php`/`add_wiki_group.php` take a Wikidata Q-id, or a
MusicBrainz artist id/URL, which is resolved to its Q-id through MusicBrainz's own `wikidata` link.

## Wikidata reload

`reloadFromWikidata( ?string $pQid = null )` runs the whole cascade: fetch the entity
(`Special:EntityData/<Qid>.json`, no key), cache the raw JSON on the `wikidata` xref's `data` (so an
unmapped property can be mined later without refetching), write the external ids, this type's own
dates (`biographyDateProps()`: dob/dod or formed/disbanded), the P18 image (downloaded from Commons)
and role tags, then the biography. Used by the add pages' initial save and the **Reload from
Wikidata** button on the edit page (a self-gated fragment in contact's generic
`content_edit_mini_tpl` slot).

| xref item | Wikidata property |
|---|---|
| `imdb` | `P345` |
| `tmdb` | `P4985` |
| `tvdb` | `P7920` |
| `musicbrainz` | `P434` |
| `viaf` | `P214` |
| `openlibrary` | `P648` |
| `official_site` | `P856` |
| `discogs_artist` | `P1953` |

**Biography** comes only from Wikipedia: the entity's `sitelinks.enwiki` title fed to Wikipedia's
REST summary endpoint (`fetchWikipediaSummary()`). TMDb's biography is person/film-cast only, so
`fetchTmdbBiography()` is kept for a later film/TV use but not called here.

## MusicBrainz fallback

A person MusicBrainz knows but Wikidata doesn't is created from MusicBrainz's own artist record
(`createFromMusicBrainz()`/`applyMusicBrainzData()`): name, sort name, type, life span, Discogs
link, role tags from the MusicBrainz type (`MUSICBRAINZ_TYPE_MAP` for groups). Its identity is the
`musicbrainz` xref; its credit `xkey` is the Discogs artist id. **Reload from MusicBrainz**
(`reloadFromMusicBrainz()`) refreshes it - and checks Wikidata again first, switching to the full
Wikidata cascade once an item appears.

## Creating contacts from the music library

### People pass - `load_wiki_people.php`

The per-artist step of fisheyemedia's one-folder workflow (registered as its `music_artist_tools`
entry, so `load_music.php`'s Process opens it straight on the new gallery). It surveys every
album folder of one artist/composer gallery - including the albums inside groups and collections
(`FisheyeAlbum::surveyArtistCredits()`) - for every distinct MusicBrainz artist id in the
album-artist and track-artist tags, and resolves each:

1. a contact already holding that MusicBrainz id - **linked**;
2. otherwise one Wikidata SPARQL query for the whole gallery (P434), then a contact already holding
   the matching Q-id - **linked by Wikidata id**;
3. otherwise **create** from the Wikidata item, **choose** when Wikidata has the id on more than
   one item (a duplicate on Wikidata's side - pick the right one), or **create from MusicBrainz**
   when Wikidata has none.

The page summarises the gallery (album folders, tracks, people credited, already contacts, still to
create) and lists only the next batch: **10 per submit**, with a short gap between people and
MusicBrainz's 1-request-per-second pace for the fallback, so Wikimedia and MusicBrainz don't
throttle a long run. A created contact whose name matches the gallery gets the `music_gallery` xref
back to it. When a submit leaves nothing to create (no failures, nobody unresolved, Wikidata
answering) it goes straight on to fisheyemedia's `load_album.php`; otherwise a **Continue: Load
albums** button.

All external calls go through `fetchExternal()`: one retry on HTTP 429/503 (honouring Retry-After,
capped), and on failure `getLastFetchError()` gives the reason - no response (timeout/DNS/TLS),
the service's own HTTP error text, or throttling - which the page shows with its "Wikidata lookup
failed" warning.

### Film people pass - `load_wiki_film_people.php`

The film-side counterpart of the people pass. Film cast and crew are plain text on a film's
`director`/`writer`/`star` xref rows (`xkey_ext` = the name); `FisheyeFilm::surveyCredits()`
(fisheyemedia) lists every distinct credited name with its roles, films and unlinked row ids, read
straight from `liberty_xref`. This page matches each name to an existing wiki contact and links the
rows - `xref` = the contact's `content_id`, `xkey` = its Wikidata Q-id, the same shape an album credit
takes (`FisheyeFilm::linkCreditRows()`: only live, still-unlinked film credit rows are written, so a
hand link is never overwritten).

Matching is by name only (`ContactWikiTrait::normaliseName()` folds accents/case/punctuation;
`nameForms()` also tries a "Surname, Forename" title flipped; `nameIndex()` indexes every wiki
individual and group once per request). One match is pre-ticked, several are a radio choice, none is
listed (most-credited first) but cannot be actioned yet. The posted contact id is re-checked against
the name's candidates, never trusted. Link is an in-place update, which marks the row hand-owned for
`reconcileItem()`: a later Plex reload leaves a linked credit alone (`kept_local`). The film page
(`view_film.tpl`) links a linked name to `index.php?content_id=`, which the dispatcher routes to the
contact's own page.

**Stage 2 - "Look up next batch"** (opt-in: nothing touches the network until pressed). For the
most-credited people still without a contact, 10 at a time:

1. **TMDb person id** - the person's films' own `tmdb` ids (`FisheyeFilm::tmdbIdsByFilm()`), then each
   film's TMDb credits (`fetchTmdbMovieCredits()`, cast + crew, cached per request); the credited name
   (normalised) is looked up in them (`findTmdbPersonForCredit()`, up to 6 films, stops when two agree).
   One id = that person; several = two people of that name, chosen by radio.
2. **Wikidata Q-id** - P4985 (TMDb person id) via one SPARQL query for the batch
   (`lookupWikidataByTmdbPersonIds()`); where Wikidata has none, TMDb's own external ids
   (`fetchTmdbPerson()` `wikidata_id`) are the fallback.
3. **Existing contact first** - one already holding the TMDb id or Q-id (`findContactByTmdbId()`,
   `findContactByWikidataQid()`) is linked, never duplicated.
4. **Create** - from the Wikidata item (`createFromWikidata()`, the full reload cascade: ids, dates,
   biography, image), or from TMDb alone when Wikidata has no item (`createFromTmdb()`/`applyTmdbData()`:
   name, `tmdb`/`imdb` ids, dob/dod, biography, profile photo, a WP01/WP02/WP07 tag from TMDb's
   `known_for_department`). A TMDb-only contact's identity is its `tmdb` xref and its credit `xkey` is
   empty until a Wikidata item appears. The contact always carries the TMDb id it was found by.
5. **Link** the person's unlinked credit rows (`xref` = contact, `xkey` = Q-id).

The form posts `pick[key]` = `<tmdb id>:<Q-id or empty>`; both are re-validated server side. Reviewed
list, 10 per submit with a gap between creations; people unticked/unresolved are stepped past
(`start`). "Not resolved" gives the reason (no TMDb token, no film with a TMDb id, TMDb lookup failed,
or not found in the films' TMDb credits under that name).

Known gaps: groups (an orchestra credited as a star) are only matched by name, not created; a TMDb-only contact's *Reload from Wikidata* button has no TMDb
reload path yet (it reports no Wikidata id); the Wikidata label shown in the review list can be the bare
Q-id when the SPARQL label service returns none (the created contact's name comes from the entity, not
that label).

#### TV, one show at a time (`load_wiki_film_people.php?scope=tv&program_id=N`)

The same page, show by show like the music loading. It is reached from the show's own page (the `program_tools` service puts a
"Load Wiki TV People" icon beside Edit/Season Order on `view_program.php`, with a badge of how many credits are still unlinked, or a
tick when none are) or from the Contact menu, where `scope=tv` first lists the shows (seasons, seasons with credits, credit rows, not
yet linked). The show's title in the page heading links back to the show, and a "Back to <show>" notice appears once every credit
is linked.

One operation prepares a show: **Reload from Plex** runs `FisheyeSeason::reloadPlexEpisodes( true )` for each season - the
data-only reload (episode details, tags and the full cast are refreshed; thumbnails, resolution and audio already stored are kept
and nothing is fetched over HTTP, so a whole show is a few seconds) - and each season reload rebuilds its credit directory
(`deriveCreditDirectory()`, rule B decides which cast get a season row; directors and writers always do). It works in time-boxed
batches (25 s) with a Continue button as a guard for a very large show. Then the survey covers the show's seasons and the program's own
star rows (`FisheyeCredits::survey()`), stage 1 name-matches, and stage 2 asks TMDb for the show's
**aggregate credits** (`fetchTmdbTvCredits()`, one call for the whole show via the program's `tmdb` TV id,
`findTmdbPersonForTvCredit()`) instead of each film's. Everything after the TMDb person id is identical (Wikidata P4985,
existing contact, create from Wikidata or TMDb, link). TMDb candidates are first **filtered by the credit's role** (`star` = a cast entry,
`director` = crew job Director, `writer` = crew department Writing - an actor and a director of one name are different people). TMDb often
holds several records for one person (the same writer under two ids): within one show, the same name doing the same job is **one
person** - the record with a Wikidata item (else the lowest id) is used, the others are kept as **aliases** on the contact's `tmdb` xref
(`data` = `{"also":["123"]}`, `addTmdbAliases()`, found again by `findContactByTmdbId()`); two different Wikidata items stay a choice.
**People Plex tags but TMDb lacks** (Pat Williams, Ted Mann on Andromeda: TMDb's crew list is incomplete) are "not resolved", but are no longer a dead end: `searchWikidataByName()`
(Wikidata `wbsearchentities`, exact label/alias match, disambiguation pages dropped, up to 12 searches per page) lists candidate items with their descriptions (a screen/writing-job description is
ticked as 'likely'), and each person also has "a contact from the name only" (`createNameOnly()`, tagged WP01/WP02/WP07 from the credit roles). Nothing is created until the person is
ticked and a radio chosen; the pick is `0:Q123` (a Wikidata item chosen by name, no TMDb record) or `0:` (name only). Batch size is 40 (lookup and create cap); a batch of 40 on NCIS
(31 Wikidata + 9 TMDb-only) looked up in 2.7 s and created in 11.4 s. The list at the foot of the page is only people the lookup offset has stepped past ("Stepped past (N)"), so a new
show shows no list. **Created by**: the TV page's Reload from Plex also writes the show's creators onto the program as `creator` rows (`fetchTmdbCreators()`, first batch only; shown as "Created by" on the show page).
Candidates for a person not on TMDb are ranked by **role fit** (`searchWikidataByName( $name, $roles )`: a director credit prefers a description containing "director", a writer credit "writer/screenwriter/
dramatist...", a star "actor/actress...", a creator "creator/producer/writer/director"); a fit gets a green tick and is listed first, a merely screen-related description a grey tick. A show's **creator** (Gene Roddenberry on Andromeda) is on the TMDb show record's `created_by`, not in its aggregate credits, so `fetchTmdbTvCredits()` adds the
creators (one extra `/tv/{id}` call) as writers - Plex credits them as such. The page lists **whoever the lookup offset has stepped past** (up to 200) under the lookup,
with a "Look them up again from the top" button, because people are stepped past (skipped, or left unresolved) by the lookup offset and would otherwise vanish from view.
People left unticked stay at the top of the list until decided or skipped; only people with nothing to act on (not found on TMDb) are
stepped past. Linking sets `xref`/`xkey` on every season row and the program row for that name; a later season reload carries the link
across. There is no minimum-episodes control: everyone the season directories hold is listed. Linked names are reused, so a person
resolved on one show arrives already linked on the next.

#### Creating contacts quickly: the Wikimedia prefetch

A contact made from a Wikidata item needs three Wikimedia records - the entity, the English Wikipedia summary and the Commons photo - and
fetched one after another (and the entity used to be fetched twice) they were ~68% of the ~2.2 s per contact, plus a 0.5 s pause between
people. `ContactWikiTrait::prefetchWikidata()` fetches them for the whole batch before the create loop: the entities first, then every summary
and photo together, with at most `WikimediaCache::CONCURRENCY` (5) requests in flight (`WikimediaCache::multiFetch()`, cURL multi, HTTP/2).
They go into `WikimediaCache` (per request; photos as temp files removed at shutdown), and `fetchWikidataEntity()`, `fetchWikipediaSummary()` and
`downloadCommonsFile()` read it first - so `createFromWikidata()`/`reloadFromWikidata()` are unchanged, find everything ready, and the duplicate entity
fetch is gone. Only successes are cached; whatever the prefetch could not get is fetched the old sequential way with its own 429/503 retry, and the
inter-person pause is only taken before a person whose data was not prefetched. Without the cURL extension `multiFetch()` returns nothing and
everything is fetched sequentially as before. Measured on desktop (NCIS): a batch of 20 (15 Wikidata, 5 TMDb-only) creates in 6.2 s (3.1 s of it the
parallel fetch) against 35.8 s for 16 all-Wikidata contacts sequentially. The profile that led here: of 15.3 s for 8 contacts, 10.4 s network,
~3.5 s pauses, only ~1.3 s our own database work - so no change was made to xref writes.

### Artists pass - `load_wiki_artists.php`

Surveys every top-level Music gallery for a linked contact (`music_gallery`), and for the gap set
resolves a representative album's `mb_artistid` through MusicBrainz (`lookupMusicBrainzArtist()` -
its type picks individual or group, its `wikidata` link supplies the Q-id). Nothing is created
until the reviewed list is submitted. Largely superseded by the people pass, which covers everyone
credited rather than just the gallery's own artist.

## Role tags

Set from Wikidata on create/reload: occupations (P106) through `ContactWikiIndividual::OCCUPATION_MAP`
(actor, director, composer/songwriter, singer, musician and instrumentalists, screenwriter,
conductor...), and instance-of (P31) through `ContactWikiGroup::GROUP_TYPE_MAP` (musical group/rock
band, orchestra, choir, ensemble, production company, record label). Both maps are curated, not
exhaustive - add a Q-id when a real contact shows it's missing.

## Lists and menu

- **`list_wiki.php`** - wiki individuals and groups only, with contact's type-filter rows (one row
  of WPxx/WBxx tags per class, each with an All toggle) and Types/Gallery/Information columns.
  The Information summary (type names, dates, Wikidata id, MusicBrainz id, music gallery link) comes
  from `enrichListRows()`, registered as `contact_list_row_function`, so contact's own
  `list_contacts.php` shows it for wiki rows too.
- **Contact menu** - this package's section (Wiki Contacts, Add Wiki Individual/Group, Load Wiki
  Artist Contacts, Load Wiki People) is included in contact's own menu through `contact_menu_tpl`.

## Services this package registers

| Service value | Read by | Purpose |
|---|---|---|
| `content_edit_mini_tpl` | contact's edit page | Reload from Wikidata / MusicBrainz buttons |
| `contact_menu_tpl` | contact's menu | this package's menu section |
| `contact_list_row_function` | `Contact::getList()` | Information summary on wiki rows |
| `credit_role_map` | fisheyemedia album credits | WP03→composer, WP08→conductor, WB02→orchestra, WP06/WB03/WB04→performer |
| `music_artist_tools` | fisheyemedia `load_music.php` | "Load its contacts" - the people pass as the per-artist step |

## Admin settings

`admin/admin_contactwiki_inc.php`:

- `contactwiki_api_contact` - **Contact address (User-Agent)**. Wikimedia and MusicBrainz both ask
  clients to identify themselves; without it, long runs are throttled sooner.
- `contactwiki_tmdb_token` - TMDb API Read Access Token. Used by the film and TV people tools (who a credited name is on TMDb, contacts for people
  Wikidata lacks); without it they cannot look anyone up.
- `contactwiki_photo_width` - **Photo width (pixels)**, default 400 (the size fisheyemedia stores its stills at): Commons sends a copy resized to this width
  (`commonsPhotoUrl()`) instead of the original upload; a smaller image is never enlarged; `original` fetches the file as uploaded. The first 645 photos
  fetched without a cap totalled 1.25 GB (230 over 1 MB, the largest 171 MB); at 400 px a typical portrait is ~125 KB. Applies to photos fetched from now on.
  A re-rendered file (Commons turns an SVG/TIFF into PNG/JPEG) is stored under its real extension (`imageExtensionOf()`). Loading better-quality or
  alternative images is intended as a later action on the contact's edit page.

## Not yet built

- Film/TV cast and crew: the film people pass and its per-show TV scope (above) match, resolve through TMDb/Wikidata,
  create and link.
- Group membership over time (see the open question above), and creating members' contacts from a
  group's Wikidata claims.
- A home for place of birth/death and date of death beyond the `dod` xref (DOB is
  `liberty_content.event_time`).
