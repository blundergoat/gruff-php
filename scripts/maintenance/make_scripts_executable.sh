#!/usr/bin/env bash

set -euo pipefail

# Script to recursively make all .sh scripts in scripts/ executable

# Color functions for output
info() {
    echo -e "\033[32mINFO:\033[0m $1"
}

warn() {
    echo -e "\033[33mWARN:\033[0m $1"
}

err() {
    echo -e "\033[31mERROR:\033[0m $1"
}

show_help() {
    cat << EOF
Usage: $0 [OPTIONS] [PATH]

Recursively makes all .sh scripts in scripts/ executable (chmod +x).

OPTIONS:
    -h, --help      Show this help message
    -n, --dry-run   Show what would be made executable without actually doing it

EXAMPLES:
    $0                              # Make .sh scripts executable in scripts/
    $0 --dry-run                    # Preview what would be made executable
EOF
}

# Resolve scripts/ directory relative to repo root
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPTS_DIR="$REPO_ROOT/scripts"
DRY_RUN=false

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -n|--dry-run)
            DRY_RUN=true
            shift
            ;;
        -*)
            err "Unknown option: $1"
            show_help
            exit 1
            ;;
        *)
            err "Unknown argument: $1"
            show_help
            exit 1
            ;;
    esac
done

if [[ ! -d "$SCRIPTS_DIR" ]]; then
    err "Directory does not exist: $SCRIPTS_DIR"
    exit 1
fi

info "Searching for .sh scripts in: $SCRIPTS_DIR"

SCRIPT_FILES=()
while IFS= read -r -d '' file; do
    SCRIPT_FILES+=("$file")
done < <(find "$SCRIPTS_DIR" -name "*.sh" -type f -print0 2>/dev/null || true)

if [[ ${#SCRIPT_FILES[@]} -eq 0 ]]; then
    info "No .sh scripts found"
    exit 0
fi

info "Found ${#SCRIPT_FILES[@]} scripts (.sh)"

processed_count=0
already_executable=0

for file in "${SCRIPT_FILES[@]}"; do
    # Check if already executable
    if [[ -x "$file" ]]; then
        if [[ "$DRY_RUN" == true ]]; then
            echo -e "\033[90mAlready executable:\033[0m $file"
        fi
        already_executable=$((already_executable + 1))
    else
        if [[ "$DRY_RUN" == true ]]; then
            echo -e "\033[36mWould make executable:\033[0m $file"
        else
            if chmod +x "$file" 2>/dev/null; then
                echo -e "\033[32mMade executable:\033[0m $file"
                processed_count=$((processed_count + 1))
            else
                warn "Failed to make executable: $file"
            fi
        fi
    fi
done

if [[ "$DRY_RUN" == true ]]; then
    non_executable=$((${#SCRIPT_FILES[@]} - already_executable))
    info "DRY RUN: Would make $non_executable scripts executable ($already_executable already executable)"
else
    info "Made $processed_count scripts executable ($already_executable were already executable)"
fi
