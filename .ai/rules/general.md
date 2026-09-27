---
paths:
  - '**/*.md'
---

# General

## vp check --fix reformats Markdown repo-wide, not just the files you touched
CLAUDE.md warns that `vp` owns TypeScript and CSS formatting. It also owns Markdown, and a bare `npx vp check --fix` rewrites `.md` files anywhere in the repo — `.ai/rules/*.md` frontmatter indentation, index.md table padding, and `requirements/*.md` paragraph wrapping all get reformatted even when your change touched none of them.

They appear in `git status` as your changes. Check `git diff --stat` before committing and stage explicit paths rather than `git add -A`; the churn is whitespace-only and blocks nothing, so leave it uncommitted rather than spending a commit on it.

It can also reindent a `.md` code block's continuation lines in a way that reads worse than the original — another reason not to sweep it in.
