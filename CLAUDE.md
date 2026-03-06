- THIS is a NEOS/FLOW Package!!!
- Lint (syntax check all PHP files): `mise run lint`
- Run all unit tests: `mise run test`
- Run a specific test file: `mise run test:unit Tests/Unit/Explore/ToolContextTest.php`
- **Always lint before and after editing PHP files.**
- TRY TO NOT RUN RAW COMMANDS — use mise tasks instead. ASK before changing mise task definitions.

Coding practices:
- Either add a short "why" comment at the doc comment of a class, or add a "@see [classname-with-why-comment] for context" comment accordingly.
- in PHPdocs, if referencing other classes, use {@see [classname]} so that it is auto-clickable in IDEs.
- Mark each class with either @internal [ 1 sentence explanation why] or @api [ 1 sentence explanation why] (ask if unsure).
- Use modern PHP 8.4 syntax.
- Interfaces should end with "Interface" (e.g `ContentGraphProjectionInterface`)
- SMALL, WELL REVIEWABLE, SELF DESCRIBING COMMITS. You can create commits (but let me know), but DO NOT PUSH THEM.
