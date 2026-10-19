package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

private val SCORE_FALLBACK = listOf("Unable", "Maximal assistance", "Moderate assistance", "Minimal assistance / prompts", "Independent")
private val LEVEL_FALLBACK = listOf("Not started", "Introduced", "Practicing", "Proficient", "Mastered")

/** "T of M (P%)", or "T on a range of L to M (P%)" when the lowest total is not 0. */
fun scoreText(total: Int, max: Int, pct: Double, items: Int, scaleMin: Int, scaleMax: Int): String {
    val low = if (scaleMax <= scaleMin) 0 else items * scaleMin
    val p = "${Math.round(pct)}%"
    return if (low == 0) "$total of $max ($p)" else "$total on a range of $low to $max ($p)"
}

/** A client's scored assessments, and giving a new one. */
@Composable
fun ScoredAssessmentsScreen(api: Api, nav: NavHostController, clientId: Int) {
    val state = rememberLoaded(clientId) { api.call("scored_assessments", mapOf("client" to clientId.toString())).optJSONArray("assessments").objects() }
    Page {
        PrimaryButton("Give an assessment") { nav.navigate("scoredNew/$clientId") }
        Show(state, "scored assessments") { list ->
            Heading("Scored assessments (${list.size})")
            if (list.isEmpty()) Text("No scored assessments yet.")
            list.forEach { a ->
                ListRow(listOf(a.optString("instrument") + ", " + dayText(a.str("assessed_on")),
                    scoreText(a.optInt("total_score"), a.optInt("max_score"), a.optDouble("pct", 0.0), a.optInt("item_count"), a.optInt("scale_min"), a.optInt("scale_max")) + (a.str("assessor")?.let { ", by $it" } ?: "")),
                    action = "read it", onClick = { nav.navigate("scored/${a.optInt("id")}") })
            }
        }
    }
}

@Composable
fun ScoredAssessmentScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) {
        val r = api.call("scored_assessment", mapOf("id" to id.toString()))
        val labels = try {
            api.call("instrument_items", mapOf("id" to (r.optJSONObject("assessment")?.optInt("instrument_id") ?: 0).toString())).optJSONArray("scale_options").objects().associate { it.optInt("value") to it.optString("label") }
        } catch (e: ApiException) { emptyMap() }
        r to labels
    }
    Show(state, "the assessment") { (r, labels) ->
        val a = r.optJSONObject("assessment") ?: JSONObject()
        val items = r.optJSONArray("items").objects()
        Page {
            Heading(a.optString("instrument"), large = true)
            ListRow(listOf("Given " + dayText(a.str("assessed_on")) + (a.str("assessor")?.let { " by $it" } ?: "")))
            ListRow(listOf("Score: " + scoreText(a.optInt("total_score"), a.optInt("max_score"), a.optDouble("pct", 0.0), items.size, a.optInt("scale_min"), a.optInt("scale_max"))))
            a.str("notes")?.let { ListRow(listOf("Notes: $it")) }
            Heading("Items")
            items.forEach { i -> ListRow(listOf(i.optString("label"), "${i.optInt("score")}" + (labels[i.optInt("score")]?.let { ", $it" } ?: ""))) }
        }
    }
}

/** Give an assessment: every item rated before it can be saved; a saved one cannot be changed. */
@Composable
fun ScoredNewScreen(api: Api, nav: NavHostController, clientId: Int) {
    val instruments = rememberLoaded(clientId) { api.call("instruments").optJSONArray("instruments").objects() }
    var chosen by rememberSaveable { mutableStateOf("") }
    val items = rememberLoaded(chosen) { if (chosen.isEmpty()) null else api.call("instrument_items", mapOf("id" to chosen)) }
    var scores by remember(chosen) { mutableStateOf<Map<Int, Int>>(emptyMap()) }
    var date by rememberSaveable { mutableStateOf(today()) }
    var notes by rememberSaveable { mutableStateOf("") }
    var confirming by remember { mutableStateOf(false) }
    var scoredKey by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Page {
        Show(instruments, "instruments") { list ->
            Choice("Instrument", chosen, listOf("" to "Choose an instrument") + list.map { it.optInt("id").toString() to (it.optString("name") + ", ${it.optInt("items")} items") }) { chosen = it }
        }
        val r = items.data
        if (chosen.isNotEmpty() && r != null) {
            val its = r.optJSONArray("items").objects()
            val scale = r.optJSONArray("scale_options").objects().map { it.optInt("value") to it.optString("label") }.ifEmpty {
                api.formList("score_labels", SCORE_FALLBACK).mapIndexed { i, l -> i to l }
            }
            Field("Date given", date, { date = it }, hint = "year-month-day")
            its.forEachIndexed { n, item ->
                val id = item.optInt("id")
                Choice("${n + 1}. " + item.optString("label"), scores[id]?.toString() ?: "", listOf("" to "Choose a rating") + scale.map { (v, l) -> v.toString() to "$v, $l" }) { v ->
                    scores = if (v.isEmpty()) scores - id else scores + (id to v.toInt())
                }
            }
            Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
            val missing = its.size - scores.size
            if (missing > 0) Text("$missing of ${its.size} items are not rated yet. Rate every item to save.")
            ErrorText(error)
            PrimaryButton("Save the assessment", busy = busy, enabled = missing == 0 && its.isNotEmpty()) { confirming = true }
            if (confirming) Confirm("Save this assessment?", "All ${its.size} items rated, total score ${scores.values.sum()}. A saved assessment cannot be changed.", "Save assessment", {
                scope.launchSave({ busy = it }, { error = it }) {
                    val s = JSONObject(); scores.forEach { (k, v) -> s.put(k.toString(), v) }
                    // 4.28.0: a retry after a lost reply saves it once (see sendOnce).
                    val res = api.callOnce("save_scored", JSONObject().put("client_id", clientId).put("instrument_id", chosen.toInt()).put("assessed_on", date.trim()).put("notes", notes.trim()).put("scores", s),
                        scoredKey, { scoredKey = it }, "the assessment")
                    Flash.pending = "Assessment saved: ${res.optInt("total")} of ${res.optInt("max")}."
                    nav.popBackStack()
                }
            }, { confirming = false })
        } else if (chosen.isNotEmpty()) Show(items, "the items") { }
    }
}

/** A client's level on each skill of a curriculum; only the levels changed here are saved. */
@Composable
fun CompetencyScreen(api: Api, clientId: Int) {
    val curricula = rememberLoaded(clientId) { api.call("curricula").optJSONArray("curricula").objects() }
    var curriculum by rememberSaveable { mutableStateOf("") }
    val skills = rememberLoaded(curriculum) { if (curriculum.isEmpty()) emptyList() else api.call("client_competency", mapOf("client" to clientId.toString(), "curriculum" to curriculum)).optJSONArray("skills").objects() }
    var changes by remember(curriculum) { mutableStateOf<Map<Int, Int>>(emptyMap()) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val levels = api.formList("competency_levels", LEVEL_FALLBACK).let { if (it.size == 5) it else LEVEL_FALLBACK }
    Page {
        Show(curricula, "curricula") { list ->
            Choice("Curriculum", curriculum, listOf("" to "Choose a curriculum") + list.map { it.optInt("id").toString() to (it.optString("name") + (it.str("tech")?.let { t -> ", $t" } ?: "")) }) { curriculum = it }
        }
        NoticeText(notice)
        if (curriculum.isNotEmpty()) Show(skills, "skills") { list ->
            Heading("Skills (${list.size})")
            list.forEach { s ->
                val id = s.optInt("id")
                val current = changes[id] ?: (if (s.isNull("level")) 0 else s.optInt("level"))
                Choice(s.optString("skill") + (if (id in changes) ", changed" else ""), current.toString(), levels.mapIndexed { i, l -> i.toString() to l }) { v ->
                    val stored = if (s.isNull("level")) 0 else s.optInt("level")
                    changes = if (v.toInt() == stored) changes - id else changes + (id to v.toInt())
                }
            }
            ErrorText(error)
            PrimaryButton(if (changes.isEmpty()) "Save" else "Save ${changes.size} changed ${if (changes.size == 1) "level" else "levels"}", busy = busy) {
                if (changes.isEmpty()) { error = "Nothing changed. Choose a new level for a skill, then save."; return@PrimaryButton }
                scope.launch {
                    busy = true; error = null
                    try {
                        val l = JSONObject(); changes.forEach { (k, v) -> l.put(k.toString(), v) }
                        val r = api.call("save_competency", body = JSONObject().put("client_id", clientId).put("levels", l))
                        notice = "${r.optInt("saved")} ${if (r.optInt("saved") == 1) "level" else "levels"} saved."
                        changes = emptyMap(); skills.reload()
                    } catch (e: ApiException) {
                        // A skill removed on the website: reload, keeping the unsaved changes.
                        error = e.message; if (e.status == 409) skills.reload()
                    } finally { busy = false }
                }
            }
        }
    }
}
