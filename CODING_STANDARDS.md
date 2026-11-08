# Coding Standards

Read during review. Formatting (Pint) and types (Larastan) are enforced by `composer check`; these are the judgement calls no tool makes.

## Tests

- Tests isolate side effects: fake every disk the code writes to (`Storage::fake`) and send log channels to a `NullHandler`, so any run, red or green, leaves `storage/` untouched.
- Tests go through the seams agreed in the feature's spec (artisan commands, Livewire pages) and assert observable results: database rows, batch and failed-row records, command output.
- A test of a rejected input asserts the specific reason, so a different rule rejecting the same input fails the test.

## Docs

- Every claim in `README.md` or `CLAUDE.md` about behaviour, configuration or commands matches the code in the same diff. Run the `docs-guard` skill on doc changes.
- Each rule has one home and other docs point to it. Import rules live in the README's "CSV Import System" section.

## Code

- New code matches the surrounding code's comment density, naming and idiom.
