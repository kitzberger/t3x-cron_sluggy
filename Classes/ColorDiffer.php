<?php

namespace Cron\CronSluggy;

/**
 * Call ->getDifference(str1, str2) to get a visual HTML diff on both strings splitted by "/"
 */
class ColorDiffer
{
    public function getDifference($str1, $str2) {
        $words1 = explode("/", $str1 ?? '');
        $words2 = explode("/", $str2 ?? '');

        $lcs = $this->longestCommonSubsequence($words1, $words2);
        return $this->highlightDifferences($words1, $words2, $lcs);
    }

    private function longestCommonSubsequence($words1, $words2) {
        $m = count($words1);
        $n = count($words2);
        $L = array_fill(0, $m+1, array_fill(0, $n+1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($words1[$i-1] == $words2[$j-1]) {
                    $L[$i][$j] = $L[$i-1][$j-1] + 1;
                } else {
                    $L[$i][$j] = max($L[$i-1][$j], $L[$i][$j-1]);
                }
            }
        }

        $lcs = [];
        $i = $m;
        $j = $n;
        while ($i > 0 && $j > 0) {
            if ($words1[$i-1] == $words2[$j-1]) {
                array_unshift($lcs, $words1[$i-1]);
                $i--;
                $j--;
            } elseif ($L[$i-1][$j] > $L[$i][$j-1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $lcs;
    }

    private function highlightDifferences($words1, $words2, $lcs) {
        $result = '';
        $i = 0;
        $j = 0;
        $k = 0;

        while ($k < count($lcs)) {
            while ($i < count($words1) && $words1[$i] != $lcs[$k]) {
                $result .= '<span style="color:red;">' . htmlspecialchars($words1[$i]) . '</span>/';
                $i++;
            }
            while ($j < count($words2) && $words2[$j] != $lcs[$k]) {
                $result .= '<span style="color:green;">' . htmlspecialchars($words2[$j]) . '</span>/';
                $j++;
            }
            $result .= htmlspecialchars($lcs[$k]) . '/';
            $i++;
            $j++;
            $k++;
        }

        while ($i < count($words1)) {
            $result .= '<span style="color:red;">' . htmlspecialchars($words1[$i]) . '</span>/';
            $i++;
        }

        while ($j < count($words2)) {
            $result .= '<span style="color:green;">' . htmlspecialchars($words2[$j]) . '</span>/';
            $j++;
        }

        return rtrim($result, '/');
    }
}

