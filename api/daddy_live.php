# -*- coding:utf-8 -*-
from future import annotations

import urllib.parse
from typing import Union, Tuple, Any
from sanic.log import logger
from curl_cffi import requests, CurlHttpVersion
from curl_cffi.requests import AsyncSession

BASE_URL = "https://dlhd.so"
BASE_M3U8_URL = "https://webhdrunns.mizhls.ru/lb/premium{channel_id}/index.m3u8"

DEFAULT_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36",
    "Referer": "https://quest4play.xyz/",
    "Origin": "https://quest4play.xyz",
    "Accept": "*/*",
    "Accept-Language": "en-US,en;q=0.9",
    "Accept-Encoding": "gzip, deflate",
    "sec-ch-ua-platform": "Windows",
    "sec-ch-ua-mobile": "?0",
    "Upgrade-Insecure-Requests": "1",
    # "sec-ch-ua": '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
    "Sec-Fetch-Dest": "document",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "none",
    "Sec-Fetch-User": "?1",
    "Sec-GPC": "1",
}


async def async_get(uri: str, proxy_host: str = None) -> bytes:
    async with AsyncSession(verify=False, proxy=proxy_host,impersonate="safari17_0") as s:
        try:
            r = await s.get(uri, headers=DEFAULT_HEADERS,
                            timeout=15,
                            http_version=CurlHttpVersion.V1_1)
            return r.content
        except Exception as e:
            logger.error(f"Failed to get {uri}, {e}")
            return b""

async def get_final_m3u8_url(channel_id: int, proxy_host: str = None) -> tuple[str, bool]:
    """
    获取最终的m3u8地址, 这里需要注意, 有些m3u8包含子m3u8, 需要递归获取
    :param channel_id:
    :param proxy_host:
    :return:
    """
    url = BASE_M3U8_URL.format(channel_id=channel_id)
    src_url = url
    m3u8_content = None
    while True:
        new_url = src_url
        async with AsyncSession(verify=False, proxy=proxy_host, impersonate="safari17_0") as s:
            try:
                r = await s.get(src_url, headers=DEFAULT_HEADERS,
                                timeout=15,
                                http_version=CurlHttpVersion.V1_1)
                m3u8_content = r.text
                if r.redirect_count > 0:
                    new_url = r.url
            except Exception as e:
                logger.error(f"Failed to get {src_url}, {e}")
                return "", False

        if new_url.rsplit("/", 1)[0] != src_url.rsplit("/", 1)[0]:
            # 说明重定向了, 需要更新url
            src_url = new_url
        logger.info(f"m3u8_content: {m3u8_content}")
        lines = m3u8_content.strip().split("\n")

        if lines[-1].find(".m3u8") != -1:
            # 说明包含子m3u8, 那么需要递归获取
            # 判断是否包含域名
            if lines[-1].startswith("http"):
                src_url = lines[-1]
            else:
                # 使用urllib.parse.urljoin拼接
                src_url = urllib.parse.urljoin(src_url, lines[-1])
            continue
        else:
            return src_url, False
    return "", False

async def get_m3u8_content(url: str, proxy_host: str = None) -> str:
    """
    获取m3u8内容
    :param url:
    :param proxy_host:
    :return:
    """
    content = await async_get(url, proxy_host)
    if not content:
        return ""
    m3u8_content =  content.decode()
    if m3u8_content.find("key2.mizhls.ru") != -1:
        m3u8_content = m3u8_content.replace("key2.mizhls.ru", "key.mizhls.ru")
    # 如果切片文件不包含域名, 那么需要拼接
    lines = m3u8_content.split("\n")
    for index, line in enumerate(lines):
        if line.startswith("#") or not line:
            continue
        lines[index] = urllib.parse.urljoin(url, line)
    return "\n".join(lines)
