from __future__ import annotations

import argparse
import html
import json
import mimetypes
import re
import zipfile
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse

import mammoth
import requests
from bs4 import BeautifulSoup
from docx import Document


ROOT = Path(__file__).resolve().parents[1]
ENV_PATH = ROOT / ".env"
BLOG_POSTS_DIR = ROOT / "Blog Posts"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/123.0.0.0 Safari/537.36"
)

STYLE_MAP = """
p[style-name='Title'] => h1:fresh
p[style-name='Heading 1'] => h2:fresh
p[style-name='Heading 2'] => h2:fresh
p[style-name='Heading 3'] => h3:fresh
p[style-name='Heading 4'] => h4:fresh
""".strip()

PUBLISH_DATES = {
    1: datetime(2023, 11, 14, 10, 17, 0),
    2: datetime(2023, 12, 8, 13, 42, 0),
    3: datetime(2024, 2, 21, 9, 25, 0),
    4: datetime(2024, 3, 7, 15, 11, 0),
    5: datetime(2024, 5, 18, 11, 36, 0),
    6: datetime(2024, 9, 12, 14, 20, 0),
    7: datetime(2024, 11, 5, 10, 8, 0),
    8: datetime(2024, 12, 22, 16, 47, 0),
    9: datetime(2025, 2, 11, 9, 54, 0),
    10: datetime(2025, 3, 27, 12, 31, 0),
}


@dataclass
class PostDraft:
    number: int
    path: Path
    title: str
    content_html: str
    image_name: str
    image_bytes: bytes
    image_mime: str
    publish_at: datetime


class ImportError(RuntimeError):
    pass


def load_env(path: Path) -> dict[str, str]:
    if not path.exists():
        raise ImportError(f"Missing environment file: {path}")

    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def post_number_from_name(path: Path) -> int:
    match = re.match(r"^(\d+)_", path.stem)
    if not match:
        raise ImportError(f"Could not determine post number from filename: {path.name}")
    return int(match.group(1))


def sorted_docx_files(paths: Iterable[Path]) -> list[Path]:
    return sorted(paths, key=lambda path: post_number_from_name(path))


def first_non_empty_paragraph(doc_path: Path) -> str:
    document = Document(doc_path)
    for paragraph in document.paragraphs:
        text = paragraph.text.strip()
        if text:
            return text
    raise ImportError(f"No text found in {doc_path.name}")


def extract_first_embedded_image(doc_path: Path, title: str) -> tuple[str, bytes, str]:
    with zipfile.ZipFile(doc_path) as archive:
        media_names = sorted(
            name for name in archive.namelist() if name.startswith("word/media/")
        )
        if not media_names:
            raise ImportError(f"No embedded image found in {doc_path.name}")

        first_media_name = media_names[0]
        image_bytes = archive.read(first_media_name)
        extension = Path(first_media_name).suffix.lower() or ".png"
        image_name = f"{slugify(title)}{extension}"
        image_mime = mimetypes.guess_type(image_name)[0] or "application/octet-stream"
        return image_name, image_bytes, image_mime


def slugify(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")
    return slug or "blog-post"


def clean_html(html_value: str, title: str) -> str:
    soup = BeautifulSoup(html_value, "html.parser")

    for image in soup.find_all("img"):
        image.decompose()

    for anchor in soup.find_all("a"):
        if not anchor.text.strip() and not anchor.attrs.get("href"):
            anchor.decompose()

    first_element = next(
        (
            child
            for child in soup.contents
            if getattr(child, "name", None) or str(child).strip()
        ),
        None,
    )
    if getattr(first_element, "get_text", None):
        first_text = normalize_text(first_element.get_text(" ", strip=True))
        if first_text == normalize_text(title):
            first_element.decompose()

    for element in soup.find_all(["p", "h1", "h2", "h3", "h4", "h5", "h6"]):
        if not element.get_text(" ", strip=True) and not element.find("img"):
            element.decompose()

    content = "".join(str(child) for child in soup.contents).strip()
    if not content:
        raise ImportError(f"Converted HTML was empty for title: {title}")
    return content


def normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(value)).strip()


def convert_docx_to_html(doc_path: Path, title: str) -> str:
    with doc_path.open("rb") as file_obj:
        result = mammoth.convert_to_html(file_obj, style_map=STYLE_MAP)
    return clean_html(result.value, title)


def build_draft(doc_path: Path) -> PostDraft:
    number = post_number_from_name(doc_path)
    title = first_non_empty_paragraph(doc_path)
    content_html = convert_docx_to_html(doc_path, title)
    image_name, image_bytes, image_mime = extract_first_embedded_image(doc_path, title)

    if number not in PUBLISH_DATES:
        raise ImportError(f"No publish date mapping found for post #{number}")

    return PostDraft(
        number=number,
        path=doc_path,
        title=title,
        content_html=content_html,
        image_name=image_name,
        image_bytes=image_bytes,
        image_mime=image_mime,
        publish_at=PUBLISH_DATES[number],
    )


class WordPressClient:
    def __init__(self, admin_url: str, username: str, password: str) -> None:
        parsed = urlparse(admin_url)
        self.site_root = f"{parsed.scheme}://{parsed.netloc}"
        self.admin_url = admin_url
        self.username = username
        self.password = password
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": "en-US,en;q=0.9",
            }
        )
        self.nonce: str | None = None
        self.gmt_offset_hours = 0.0

    @property
    def rest_headers(self) -> dict[str, str]:
        if not self.nonce:
            raise ImportError("REST nonce is not available")
        return {"X-WP-Nonce": self.nonce}

    def login(self) -> None:
        response = self.session.get(self.admin_url, timeout=30)
        response.raise_for_status()

        payload = {
            "log": self.username,
            "pwd": self.password,
            "rememberme": "forever",
            "wp-submit": "Log In",
            "redirect_to": f"{self.site_root}/wp-admin/",
            "testcookie": "1",
        }
        for name, value in re.findall(
            r'<input[^>]+type="hidden"[^>]+name="([^"]+)"[^>]+value="([^"]*)"',
            response.text,
            flags=re.I,
        ):
            payload[name] = value

        login_response = self.session.post(
            self.admin_url,
            data=payload,
            headers={"Referer": self.admin_url},
            timeout=30,
            allow_redirects=True,
        )
        login_response.raise_for_status()

        admin_response = self.session.get(f"{self.site_root}/wp-admin/", timeout=30)
        admin_response.raise_for_status()
        if 'id="loginform"' in admin_response.text:
            raise ImportError("WordPress login did not succeed")

        nonce_response = self.session.get(f"{self.site_root}/wp-admin/post-new.php", timeout=30)
        nonce_response.raise_for_status()
        nonce_match = re.search(
            r'wpApiSettings\s*=\s*\{[^}]*"nonce":"([^"]+)"',
            nonce_response.text,
        )
        if not nonce_match:
            raise ImportError("Could not find WordPress REST nonce in the admin page")
        self.nonce = nonce_match.group(1)

        root_response = self.session.get(f"{self.site_root}/wp-json/", timeout=30)
        root_response.raise_for_status()
        root_data = root_response.json()
        self.gmt_offset_hours = float(root_data.get("gmt_offset", 0) or 0)

    def find_existing_post(self, title: str) -> dict | None:
        response = self.session.get(
            f"{self.site_root}/wp-json/wp/v2/posts",
            params={
                "search": title,
                "status": "any",
                "context": "edit",
                "per_page": 100,
                "_fields": "id,title,link,status",
            },
            headers=self.rest_headers,
            timeout=30,
        )
        response.raise_for_status()
        for post in response.json():
            rendered = normalize_text(post.get("title", {}).get("rendered", ""))
            if rendered == normalize_text(title):
                return post
        return None

    def upload_media(self, draft: PostDraft) -> int:
        response = self.session.post(
            f"{self.site_root}/wp-json/wp/v2/media",
            headers=self.rest_headers,
            files={"file": (draft.image_name, draft.image_bytes, draft.image_mime)},
            data={"title": draft.title, "alt_text": draft.title},
            timeout=60,
        )
        response.raise_for_status()
        media = response.json()
        media_id = media["id"]

        self.session.post(
            f"{self.site_root}/wp-json/wp/v2/media/{media_id}",
            headers={**self.rest_headers, "Content-Type": "application/json"},
            data=json.dumps({"alt_text": draft.title, "title": draft.title}),
            timeout=30,
        ).raise_for_status()

        return media_id

    def create_post(self, draft: PostDraft, featured_media_id: int) -> dict:
        offset = timedelta(hours=self.gmt_offset_hours)
        local_date = draft.publish_at
        gmt_date = local_date - offset

        payload = {
            "title": draft.title,
            "content": draft.content_html,
            "status": "publish",
            "date": local_date.strftime("%Y-%m-%dT%H:%M:%S"),
            "date_gmt": gmt_date.strftime("%Y-%m-%dT%H:%M:%S"),
            "featured_media": featured_media_id,
        }

        response = self.session.post(
            f"{self.site_root}/wp-json/wp/v2/posts",
            headers={**self.rest_headers, "Content-Type": "application/json"},
            data=json.dumps(payload),
            timeout=60,
        )
        response.raise_for_status()
        return response.json()


def run_import(dry_run: bool) -> None:
    env = load_env(ENV_PATH)
    client = WordPressClient(
        admin_url=env["WORDPRESS_ADMIN_URL"],
        username=env["WORDPRESS_USERNAME"],
        password=env["WORDPRESS_PASSWORD"],
    )

    drafts = [build_draft(path) for path in sorted_docx_files(BLOG_POSTS_DIR.glob("*.docx"))]
    if not drafts:
        raise ImportError(f"No .docx files found in {BLOG_POSTS_DIR}")

    client.login()

    for draft in drafts:
        existing = client.find_existing_post(draft.title)
        publish_date = draft.publish_at.strftime("%Y-%m-%d %H:%M:%S")
        if existing:
            print(
                f"SKIP #{draft.number}: {draft.title} "
                f"(already exists as ID {existing['id']})"
            )
            continue

        if dry_run:
            print(
                f"DRY RUN #{draft.number}: {draft.title} "
                f"| publish_at={publish_date} | image={draft.image_name}"
            )
            continue

        media_id = client.upload_media(draft)
        post = client.create_post(draft, media_id)
        print(
            f"CREATED #{draft.number}: {draft.title} "
            f"| post_id={post['id']} | media_id={media_id} | "
            f"publish_at={publish_date} | link={post['link']}"
        )


def main() -> None:
    parser = argparse.ArgumentParser(description="Import Word blog posts into WordPress.")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Validate conversion and publish-date mapping without creating posts.",
    )
    args = parser.parse_args()

    run_import(dry_run=args.dry_run)


if __name__ == "__main__":
    main()
