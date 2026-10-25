<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Contracts\View\View;

/**
 * S8 M05-B02 shared link /articles/{slug}. Only public articles show their content;
 * for member or restricted articles the page shows no title or content, only a
 * prompt to open the App and log in (the link never bypasses read permission).
 */
class ArticleSharePageController extends Controller
{
    public function __invoke(string $slug): View
    {
        $article = Article::query()->where('slug', $slug)->first();
        abort_if($article === null || ! $article->isLive(), 404);

        return view('articles.share', [
            'article' => $article->visibility === Article::VISIBILITY_PUBLIC ? $article : null,
        ]);
    }
}
