# TYDAL user guide

This guide is for the people who **use** TYDAL: members of an organization who
browse, upload, describe and share its resources, and the administrators who look
after that organization. It doesn't cover installing or running TYDAL. For that,
see the [Deployment Guide](../../DEPLOYMENT.md) and the [CLI Guide](../CLI.md).

The screenshots come from a demo installation with an organization called
*Atlas Photo Collective*. Your organization's collections, workspaces and fields
will differ, but the screens are the same.

## Chapters

| | Chapter | For |
|---|---|---|
| 1 | [Getting started](01-getting-started.md): signing in, your organization, your role | everyone |
| 2 | [Finding resources](02-library.md): the library, search, filters, the resource view | everyone |
| 3 | [Adding resources](03-adding-resources.md): the upload wizard and AI suggestions (AiTy) | editors and up |
| 4 | [Tags](04-tags.md): typed tags, AI-suggested tags, tagging many resources at once | editors and up |
| 5 | [The basket and bulk actions](05-basket.md): acting on many resources | editors and up |
| 6 | [Workspaces and vaults](06-workspaces-and-vaults.md): grouping resources and sharing them outside TYDAL | admins and up |
| 7 | [Deleting and the trash](07-trash.md) | editors and up |
| 8 | [Running your organization](08-organization-settings.md): members, collections, tags | admins and owners |
| 9 | [Platform administration](09-platform-administration.md): the whole installation | platform admins |

## Roles at a glance

Everyone in an organization has one role there. Each role can do everything the
roles below it can, plus more:

| Role | Can |
|---|---|
| **Viewer** | Browse, search, open and download resources. Nothing is changed. |
| **Editor** | Upload resources, edit and tag **their own**, add resources to workspaces, use the basket, delete their own resources (to the trash). |
| **Administrator** | Edit and delete **any** resource in the organization, create and remove workspaces, create collections, manage organization tags, invite members as editor or viewer. |
| **Owner** | Everything an administrator can, plus appoint administrators and other owners, and delete the organization. |

**Platform administrator** isn't an organization role. It belongs to the people
who run the TYDAL installation itself (see [chapter 9](09-platform-administration.md)).
A platform administrator can work in any organization without being a member of it.

## Words used in this guide

- **Organization**: the space your team works in. Everything you see belongs to
  the organization chosen at the top right.
- **Resource**: one item in TYDAL, such as a photograph, a document or a recording.
  A resource has a name, a description, tags and one or more **files**.
- **Collection**: where resources live. A collection sets which fields its
  resources have (author, year, technique…), through its **scheme**.
- **Workspace**: a named group of resources, such as an exhibition, a project or a
  shortlist. A resource can be in several workspaces, and workspaces can span
  collections.
- **Vault**: how resources are shared outside TYDAL. A vault shows the resources
  of one or more workspaces to a website, an app or an AI, and only what it was
  set up to show.
- **AiTy**: TYDAL's AI assistant. It reads uploaded files and *suggests* names,
  descriptions and tags. A person always decides whether to keep them.
- **Basket**: your personal selection of resources, for acting on many at once.

## Known issues

Open problems are tracked on GitHub, so this list is always up to date:
[open bug reports](https://github.com/eonity-org/tydal/issues?q=is%3Aissue%20state%3Aopen%20label%3Abug).
If something in TYDAL doesn't behave as this guide describes, check there first.

---

[Getting started](01-getting-started.md) →
