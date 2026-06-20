# Course Builder — Operator Guide

A plain-language guide for the person building courses. It walks you from a
source document (or a topic) to a published Moodle course you've reviewed and
signed off on. No technical background needed.

> **Status: Beta.** The course builder works end to end and is in pilot. A few
> limits are called out under [Good to know & limits](#good-to-know--limits) —
> read those before you rely on it for anything important.

---

## 1. What it does

The course builder turns **a document** — an SOP, a policy, a handbook, a slide
deck — **or a topic** into a structured, draft Moodle course in minutes.

You give it your material; it drafts the course: the sections, the reading, the
quiz questions, and (optionally) images, using your site's AI. You then review
and publish. What it produces is a **real, native Moodle course** living in your
site — not an attachment, an external link, or a PDF.

It's at its best when you have source material you want turned into training:
point it at the policy, get a draft course, check it, publish it.

---

## 2. Before you start

**Where it lives.** Open the course **category** where you want the new course to
go, open its **settings** (the gear / "More" menu on the category page), and
choose **Course builder**. That opens the hub, which lists your builds and has a
**Create a generation job** button.

**Access you need.** You need the course builder's "generate" permission in that
category — your administrator grants it. (Approving a build can be a separate
permission; if you can create but not approve, that's why.)

**Have ready** one of:
- a **source file** — PDF, Word (`.docx`), PowerPoint (`.pptx`), or plain
  text/Markdown (`.txt`/`.md`); or
- a **topic** — a sentence or two describing what the course should cover.

You can provide both — and it's the strongest combination. The **topic sets the
focus** (what the course should zero in on) and the **documents are the source of
the actual content**. For example, a broad policy manual plus the topic *"focus on
the visitor sign-in procedure"* yields a course about sign-in, written from the
manual. The topic decides what it's about; your documents stay the authority for
what it says.

---

## 3. Create a build

From the hub, click **Create a generation job** and fill in the form.

**Topic** — optional. Describe the course in a sentence or two, or leave it blank
if your uploaded file says it all.

**Source files** — upload your document(s). The course is drafted from what's in
them, so better source in means better course out.

**Generation mode** — this matters; see the box below.

> **Outline first vs. Automatic — choose deliberately.**
> **Outline first (recommended)** pauses after drafting the plan so you can
> review and edit it *before* the course is built. **Automatic** skips that
> review and builds immediately — faster, but **nothing checks the AI's work
> before the course exists**. Use **Outline first for anything that matters** —
> especially compliance, policy, or customer-facing training. Use **Automatic**
> only for low-stakes or exploratory drafts you'll scrutinise afterward.

### Your two main levers: Audience level and Length/depth

These two controls shape the whole course. Set them deliberately — they're your
main influence over what comes out. (They have help bubbles on the form too.)

**Audience level** — who the course is pitched at:

| Setting | What you get | Reach for it when… |
|---|---|---|
| **Beginner** | Assumes no prior knowledge. Defines terms, plain language, concrete examples. | New hires, all-staff rollouts, first exposure to the topic. |
| **Intermediate** | Assumes a working foundation. Uses the field's vocabulary, practical and applied. | People who do the work and need to apply or refresh it. |
| **Advanced** | Assumes real expertise. Concise and nuanced; gets into trade-offs and edge cases. | Specialists, train-the-trainer, deep-dive refreshers. |

**Length and depth** — how much course to generate:

| Setting | What you get | Reach for it when… |
|---|---|---|
| **Brief** | A few focused sections, lighter checks. | A quick refresher, a single policy point, a short awareness piece. |
| **Standard** | A moderate course. | Most everyday topics — a normal training module. |
| **Comprehensive** | Many sections, thorough, fuller assessment. | Onboarding, a certification-track course, a big SOP covered properly. |

The two combine. *Advanced + Brief* is a short, high-level refresher for experts;
*Beginner + Comprehensive* is a thorough, gentle onboarding. The builder still
adapts to what your source material can actually support — a one-page policy
won't stretch to a comprehensive course no matter the setting.

**Create job** kicks it off and takes you to the job page.

---

## 4. What happens next

After you submit, you land on the **job page**. It moves itself through the steps
— pulling text from your sources, drafting the outline, building the course,
adding the assessments — and **refreshes on its own**, so you can leave it open
or come back later. The page shows the current status and the audience/length you
asked for.

- **Outline first:** it stops at **Awaiting review** and waits for you — go to
  [Review the blueprint](#5-review-the-blueprint).
- **Automatic:** it runs straight through to **Complete** with no pause, then
  skip to [What gets built](#6-what-gets-built).

---

## 5. Review the blueprint

*(Outline-first builds only.)* On the job page, click **Review & approve**. This
is the most important screen in the tool.

> ### You are the editor of record
> The AI has drafted a course from your source. **Your job is to make sure it's
> right before anyone learns from it.** Read each section's draft against your
> source document and confirm it's faithful. For a compliance or policy course
> this is not cosmetic: an AI that paraphrases your SOP can quietly change what it
> *says*, and a course that drifts from the policy is a liability, not a typo.
> Treat the draft as a fast first draft from a junior colleague — useful, and to
> be checked — **not as an authority**. **Approve only what you'd put your name
> to.**

**What you can edit.** The course **Title** and **Description**, and for each
section: its **Title**, **Order**, **Learning objectives**, **Summary**, whether
to **generate an image** (and a hint for it), the **Assessment** type, and the
**Number of questions**. You can **Add section** or **Delete this section**.

**Ordering sections.** The **Order** field controls position. Leave it **blank to
add a section at the end** (handy when you click *Add section*), or type a number
to **place it at that position** — the others shift down, so typing `3` makes it
the third section. Positions are tidied up to 1, 2, 3… when you save.

**Regenerate a section.** Each section has a **Regenerate** button. If a section
is off, you can rewrite it by hand, or tighten its summary/objectives and
regenerate to get a fresh draft that follows your edits.

**Choose each section's assessment.** The dropdown offers three:

| Choice | What it is | Use it for… |
|---|---|---|
| **No assessment** | Just the reading; the learner marks the section done. | Background or reference material. |
| **Knowledge check** | A few questions **inline in the reading**; completes when the learner **submits**. | Reinforcing understanding as they read (formative). |
| **Graded quiz** | A separate, scored quiz the learner **must pass** to complete the section. | Where you need a demonstrated pass — compliance sign-off, certification (summative). |

When the plan is faithful and complete, click **Approve**. The course then builds.
(Editing a course you've already approved or built sends it back for re-approval —
nothing rebuilds behind your back.)

---

## 6. What gets built

A new course in **the category you launched from**, created **hidden** from
learners. Open it from the job page with **Open the course**.

Its anatomy:

- An **Introduction** overview first (pinned at the top of the course), drawn from
  your course description and a list of what's covered.
- **One section per topic**, each with the reading, an optional image, and the
  knowledge check or quiz you chose — with the reading first and the assessment
  at the end.
- A **Wrap-up** section to close.
- A **cover image**, if images were switched on.

**How completion works.** Learners complete the course by completing the
**content and assessment activities**. The Introduction and Wrap-up are
orientation and **don't count** toward completion.

---

## 7. Review and publish

1. From the job page, **Open the course**. It's hidden, so only you and staff can
   see it for now.
2. **Preview it as a learner would.** Use Moodle's **"Log in as"** a test learner
   (or your site's test user) and walk the course: the reading flow, the inline
   knowledge checks, any quiz. This is the real sign-off — see what they'll see.
3. When you're satisfied, **make the course visible** (course settings →
   Visibility → *Show*) to publish it.

**Draft-by-default is your safety rail.** Nothing reaches learners until you
unhide the course. Use the preview step — it's the difference between catching a
drifted policy section in private and explaining it after the fact.

---

## 8. If something looks off

| What you see | What it means | What to do |
|---|---|---|
| A knowledge check appears as a **separate "Knowledge check" link** instead of inline in the reading | The site's **Knowledge check filter is switched off**. The check still works — it just isn't embedded. | Ask your administrator to enable the **"Knowledge check"** filter (Site administration → Plugins → Filters). |
| The build **failed** | Something went wrong during generation (e.g. a source file couldn't be read, or the AI provider was unavailable). | The job page shows the reason. Fix the input if it points to one, and **start a new build**. If it keeps failing, contact your administrator. |
| The build **stalled** part-way | A background step hasn't run, or is stuck. | Leave the page open a few minutes (it refreshes itself). If it doesn't move, contact your administrator. |
| **"Spend cap reached"** or generation **stops early** | Your site has a generation budget (cost control) and this period's is used up. | Wait for the budget period to reset, or ask your administrator to raise the cap. |

**Discarding a build you don't want.** Delete the course through Moodle's normal
course management — open the course, go to its **settings → Delete**. The build's
record stays in the hub, but the course is removed. (Deleting from the hub itself
isn't available yet; this guide will be updated when it is.)

---

## Good to know & limits

- **Beta.** It's in pilot. Review everything before you publish — always.
- **Tested on Moodle 5.2.** That's the version it's verified against.
- **Audience level and Length/depth are set up front and fixed for a run.** To
  change them, **start a new build** — they can't be changed after generation.
- **No certificate is created.** Issuing certificates is out of scope. The
  **Wrap-up** section is a natural place for your administrator to add one if your
  site uses certificates.
- **Your uploaded source files are not attached to the built course.** They're
  used to *write* the course, but learners can't download them from it. If
  learners need the original document, add it to the course yourself. *(Attaching
  sources for learners is a candidate future option.)*
- **Generation isn't free.** Your site has spend caps; bigger and more
  comprehensive builds cost more and are likelier to hit a cap.
- **Always review before publishing.** Draft-by-default exists so unreviewed AI
  content never reaches learners by accident — don't bypass it for anything that
  matters.
