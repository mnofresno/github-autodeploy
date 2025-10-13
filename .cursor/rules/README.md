# Cursor AI Rules for Git Auto Deployment Tool

This directory contains rules and guidelines for Cursor AI to follow when generating code for this project.

## Files

### 1. `coding-standards.mdc`
Comprehensive coding standards including:
- Applies to: `**/*.php`, `**/*.yml`, `**/*.yaml`, `**/*.json`
- PHP CS Fixer rules and examples
- PSR-12 compliance
- Testing standards
- Git commit message format
- File organization
- Security practices
- Pre-commit checklist

### 2. `project-architecture.mdc`
Project architecture documentation:
- Applies to: `src/**/*.php`, `test/**/*.php`
- Core components overview
- Configuration files structure
- Command execution flow
- Placeholder system details
- Testing strategy
- Error handling
- CI/CD pipeline
- Common patterns for adding features

### 3. `cursor-instructions.mdc`
Direct instructions for Cursor AI:
- Applies to: All files (`**/*`)
- Code generation guidelines
- Code style requirements with examples
- Testing requirements (TDD)
- Placeholder system usage
- Security requirements
- Commit message format
- Common pitfalls to avoid
- Pre-commit checklist

## How Cursor Uses These Rules

Cursor AI reads these files automatically and follows the guidelines when:
- Generating new code
- Modifying existing code
- Suggesting improvements
- Writing tests
- Creating documentation

## How to Use

### For Developers

1. **Read these files** to understand project standards
2. **Reference them** when unsure about coding style
3. **Update them** when project standards change
4. **Follow the checklists** before committing

### For Cursor AI

Cursor automatically:
- Reads these files when opening the project
- Applies the rules when generating code
- Suggests code that matches the standards
- Warns about violations

## Quick Reference

### Before Every Commit

```bash
# Run linter
./linter/lint.sh --dry-run

# If issues found, fix them
./linter/lint.sh

# Run tests
composer run-script test

# Check your changes
git diff

# Commit with proper format
git commit -m "type(scope): description"
```

### Common Linter Issues

1. **Trailing whitespace**: Remove spaces at end of lines
2. **Whitespace in blank lines**: Blank lines must be completely empty
3. **Array syntax**: Use `[]` not `array()`
4. **Braces position**: Opening brace on same line
5. **Unused imports**: Remove unused use statements

## Updating These Rules

When you need to update these rules:

1. **Discuss changes** with the team
2. **Update the relevant file(s)**
3. **Commit with message**: `docs(cursor): update coding standards`
4. **Inform team members** about changes

## Integration with CI/CD

These rules align with our CI/CD pipeline:
- GitHub Actions runs the same linter
- Tests are required to pass
- Deployments only happen after successful checks

## Benefits

Following these rules ensures:
- ✅ Consistent code style across the project
- ✅ Fewer merge conflicts
- ✅ Easier code reviews
- ✅ Better maintainability
- ✅ Reduced bugs
- ✅ Faster onboarding for new developers
- ✅ AI-generated code matches project standards

## Questions?

If you have questions about these rules:
1. Check the relevant rule file for details
2. Look at existing code for examples
3. Run the linter to verify compliance
4. Ask the team

## Version

These rules are living documents and will evolve with the project.

Last updated: 2025-10-12

