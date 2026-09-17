### REDCap External Module
********************************************************************************
# Auto Complete Form Status Based on Required Fields
John Liman, Monash University
********************************************************************************
## Introduction
This External Module automatically updates the **Form Status ('Complete?')** dropdown based on required field completion. When all required fields are filled, the status is set to **Complete**; if any required field is missing, it is set to **Incomplete**.

Optionally (disabled by default), you can enable **Unverified status checking**, which marks the form as **Unverified** if any required field has not been **Verified** or **Closed** using the **Data Resolution Workflow**. Please note the checking of verified status is only applicable to 'Required' fields.

From version 2.0, you can also define **custom logic** (per instrument) that is combined with the default required fields check to determine the Complete status.

## Features
- Apply to all instruments or select specific instruments via repeatable entries
- Show, hide, or disable the **Form Status ('Complete?')** dropdown
- Support **Unverified** status through the **Data Resolution Workflow**
- Define **custom logic** per instrument, combined with the required fields check using **AND**, **OR**, or **NONE** (custom logic only)

## Instrument entries and Custom Logic (new in version 2.0)
The module is configured through one or more repeatable **entries**. Each entry determines which instruments the module applies to and, optionally, the custom logic used for those instruments. Each entry consists of:

1. **Instruments/forms** the entry applies to. This list is inclusive — one entry can name several instruments.
2. **Default checkbox** — with no instruments selected, tick "Use this entry as the default for all instruments that do not have their own entry" to make the entry a catch-all. The checkbox is only shown (and only takes effect) when the instrument list is empty — an entry either names specific instruments or acts as the catch-all, never both. An exact instrument match in another entry always takes precedence over the catch-all, regardless of the order of the entries.
3. **Custom logic** (optional), written in standard REDCap logic syntax, for example:

   ```
   [age] > 18 and [consent] = '1'
   ```

   Smart variables, event prefixes and repeat instance references are supported, e.g. `[baseline_arm_1][weight] > 0` or `[consent(1)] = '1'`. Leave the logic blank to use the required fields check only for those instruments.
4. **Combination mode**, which controls how the custom logic is combined with the default "all required fields entered" check. If not selected, **AND** is used by default:
   - **AND** (default) — the form is marked **Complete** only when all required fields are entered **and** the custom logic evaluates to true. If the custom logic is false, the form is marked **Incomplete** regardless of required fields.
   - **OR** — the form is marked **Complete** when either all required fields are entered **or** the custom logic evaluates to true.
   - **NONE** — just use the custom logic: the form is marked **Complete** when the logic is true and **Incomplete** when it is false. The required fields check is ignored entirely.

### Which instruments does the module apply to?
- If **no entries** are added, the module falls back to the instrument list saved by version 1.x (see [Upgrading from version 1.x](#upgrading-from-version-1x)), and where there is no such value it applies to **all instruments** using the required fields check only (the classic version 1.x behaviour).
- If entries exist, the module applies only to instruments covered by an entry: either named in an entry's instrument list, or caught by a catch-all entry (no instruments, default checkbox ticked). An exact instrument match takes precedence over the catch-all **regardless of entry order** — e.g. with entry 1 as the catch-all and entry 2 naming a specific form, that form uses entry 2 and every other form uses entry 1. If multiple entries name the same instrument, the first one is used. Instruments not covered by any entry are left completely alone — their status is not changed and their Form Status field is not hidden or disabled.
- To apply the module to **all** instruments while giving specific forms their own logic, add the specific entries plus one entry with the default checkbox ticked (leave its logic blank for the required fields check only).
- Entries with no instruments selected and the default checkbox unticked cover nothing and are ignored, so an accidental empty row can never change behaviour.

### Notes on behaviour
- **Unverified status checking** is preserved with **AND** (when the custom logic is true, the status falls through to the required fields result, so a form can still be marked **Unverified**) and with **OR** when the logic is false. With **NONE**, the required fields check — including verification — is skipped, so the status will only ever be **Complete** or **Incomplete**.
- If the custom logic cannot be evaluated (e.g. a syntax error or a reference to a non-existent field), the error is written to the REDCap logging module and the PHP error log, and the module falls back to the required fields check only. An invalid expression will never silently change form statuses.
- The logic is evaluated after the record is saved, so it sees the values just submitted on the form.

## Configuration (project settings)
- **Unverified status** — mark as Unverified if a required field has not been Verified or Closed via the Data Resolution Workflow (checkbox, off by default).
- **Form Status 'Complete' field action** — show as is, hide it (the default when not configured), or disable it (visible but read-only) on the data entry form.
- **Instrument entries** — repeatable group of instruments + default checkbox + optional logic + AND/OR/NONE combination, as described above. This replaces the separate "Apply to the following instruments/forms" setting from earlier versions, whose stored value is still honoured until entries are configured.

## Upgrading from version 1.x
**Upgrading is safe and requires no configuration changes.** The version 1.x "Apply to the following instruments/forms" setting (`instruments_to_be_checked`) no longer appears on the configuration page, but its stored value is still read as a fallback, so an upgraded project keeps exactly the instrument scope it had under 1.x:

- If you had selected specific instruments in 1.x, the module continues to apply to **only those instruments** (using the required fields check, as before) until you configure instrument entries.
- If you had left it blank, the module continues to apply to **all instruments**.
- As soon as you add any instrument entry, the entries take over completely and the old value is ignored.

To move a project onto the new configuration, re-create the old selection as instrument entries (with blank logic) and the fallback stops being used. The old value is left in the database untouched, so this can be done at any time.

**Server requirement:** version 2.0 targets External Module framework version 12, the same as version 1.x, so it installs on REDCap 13.1.0 or later (13.1.5 or later on the LTS release track). Any server that could run version 1.x can run version 2.0.
