#!/usr/bin/env ruby
# frozen_string_literal: true

require "json"
require "yaml"

module YoroRouteResolver
  module_function

  SHA_PATTERN = /\A[0-9a-f]{40}\z/

  def load_yaml(path)
    YAML.safe_load(File.read(path, encoding: "UTF-8"), permitted_classes: [], aliases: false)
  end

  def manifest_root
    File.expand_path("../manifests", __dir__)
  end

  def concrete_agents(agent_directory)
    Dir.glob(File.join(agent_directory, "*.toml")).map do |path|
      File.read(path, encoding: "UTF-8")[/^name\s*=\s*"([^"]+)"/, 1]
    end.compact.uniq
  end

  def expand_selector(selector, affected_components, risk_flags, context, selectors)
    definition = selectors.fetch("selectors").fetch(selector) { raise "unknown_selector: #{selector}" }
    case definition.fetch("type")
    when "component_explorer"
      affected_components.map { |component| selectors.fetch("component_explorers").fetch(component) }.compact
    when "component_explorers"
      order = selectors.fetch("component_order")
      (affected_components & order).map { |component| selectors.fetch("component_explorers").fetch(component) }.compact
    when "conditional_agent"
      (risk_flags & definition.fetch("any_risk_flags")).empty? ? [] : [definition.fetch("agent")]
    when "conditional_context_agent"
      definition.fetch("values").include?(context[definition.fetch("context_key")]) ? [definition.fetch("agent")] : []
    else
      raise "unknown_selector_type: #{definition['type']}"
    end
  end

  def repository_scope(scope, declared_components, label)
    raise "#{label}_must_be_array" unless scope.is_a?(Array)
    unknown = scope - declared_components
    raise "#{label}_contains_non_repository: #{unknown.join(',')}" unless unknown.empty?
    raise "#{label}_contains_duplicates" unless scope.uniq.length == scope.length
    scope
  end

  def adoption_path_allowed?(path, policy)
    return false unless path.is_a?(String) && !path.empty? && !path.start_with?("/")
    return false if path.split("/").include?("..")
    policy.fetch("allowed_managed_paths").fetch("exact").include?(path) ||
      policy.fetch("allowed_managed_paths").fetch("prefixes").any? { |prefix| path.start_with?(prefix) }
  end

  def approval_assignment_resolved?(approval, assignment, definition, candidate_scope, declared_components, context)
    return false if assignment.nil? || assignment == "UNRESOLVED"
    return !assignment.strip.empty? if assignment.is_a?(String)
    return false unless assignment.is_a?(Hash) && !assignment.fetch("authority", "").to_s.strip.empty?
    return true unless definition["assignment_type"] == "component_scoped"
    authorized = repository_scope(assignment.fetch("authorized_components", []), declared_components, "approval_#{approval}_authorized_components")
    return false unless authorized.sort == candidate_scope.sort
    return true unless definition["assignment_binding"] == "repository_adoption_task"

    operations = assignment.fetch("authorized_operations", [])
    return false unless operations.is_a?(Array) && operations == [context["adoption_operation"]]
    baselines = assignment.fetch("authorized_baselines", {})
    return false unless baselines.is_a?(Hash) && baselines.keys.sort == candidate_scope.sort
    candidate_scope.all? { |component| baselines[component] == context["target_baseline_commit"] && baselines[component].to_s.match?(SHA_PATTERN) }
  end

  def resolve(input, root: manifest_root, agent_directory: nil)
    routing = load_yaml(File.join(root, "routing.yaml"))
    selectors = load_yaml(File.join(root, "routing-selectors.yaml"))
    task_class = input.fetch("primary_task_class")
    risk_flags = input.fetch("risk_flags", [])
    affected = input.fetch("affected_components")
    context = input.fetch("context", {})
    raise "unsupported_task_class: #{task_class}" unless routing.fetch("primary_task_classes").include?(task_class)
    unknown_flags = risk_flags - routing.fetch("risk_flags")
    raise "unsupported_risk_flags: #{unknown_flags.join(',')}" unless unknown_flags.empty?
    unknown_components = affected - selectors.fetch("component_explorers").keys
    raise "unsupported_components: #{unknown_components.join(',')}" unless unknown_components.empty?
    component_order = selectors.fetch("component_order")
    declared_components = selectors.fetch("component_explorers").keys
    affected = component_order.select { |component| affected.include?(component) }

    route = routing.fetch("routes").fetch(task_class)
    selector_defs = selectors.fetch("selectors")
    agents = route.fetch("agents").flat_map do |entry|
      selector_defs.key?(entry) ? expand_selector(entry, affected, risk_flags, context, selectors) : [entry]
    end
    risk_flags.each do |flag|
      agents.concat(routing.fetch("risk_overrides").fetch(flag, {}).fetch("add_agents", []))
    end
    matching_profiles = selectors.fetch("route_profiles").select do |_name, candidate|
      match = candidate.fetch("match")
      match.fetch("primary_task_class") == task_class &&
        match.fetch("risk_flags").sort == risk_flags.sort &&
        component_order.select { |component| match.fetch("affected_components").include?(component) } == affected &&
        match.fetch("context", {}).all? { |key, value| context[key] == value }
    end
    raise "ambiguous_route_profile: #{matching_profiles.keys.join(',')}" if matching_profiles.length > 1
    supplied_profile = context["route_profile"]
    if supplied_profile
      raise "route_profile_mismatch: supplied=#{supplied_profile} derived=none" if matching_profiles.empty?
      derived_profile_name, profile = matching_profiles.first
      raise "route_profile_mismatch: supplied=#{supplied_profile} derived=#{derived_profile_name}" if supplied_profile != derived_profile_name
    elsif matching_profiles.length == 1
      _derived_profile_name, profile = matching_profiles.first
    else
      reviewer_mapping = selectors.fetch("review_agent_mapping")
      reviews = route.fetch("reviews").map { |review| reviewer_mapping[review] if reviewer_mapping.key?(review) }.compact
      profile = {
        "required_gates" => route.fetch("tests"),
        "required_reviewers" => reviews,
        "required_approvals" => [],
        "stop_conditions" => routing.fetch("global_stop_conditions"),
        "prohibited_behavior" => ["write_outside_resolved_scope", "bypass_protected_gate", "lower_capability_substitution"]
      }
    end

    scope_policy = selectors.fetch("write_scope_policies").fetch(task_class)
    requested_scope = context["requested_candidate_write_scope"] || context["requested_write_scope"]
    default_scope = case scope_policy.fetch("type")
                    when "none", "fixed_repositories"
                      scope_policy.fetch("default")
                    when "affected_components"
                      affected
                    else
                      raise "unknown_write_scope_policy"
                    end
    candidate_scope = repository_scope(requested_scope || default_scope, declared_components, "candidate_write_scope")
    allowed_scope = scope_policy.fetch("type") == "affected_components" ? (affected & scope_policy.fetch("allowed", affected)) : scope_policy.fetch("allowed", scope_policy.fetch("default", []))
    raise "write_scope_outside_route: #{(candidate_scope - allowed_scope).join(',')}" unless (candidate_scope - allowed_scope).empty?
    maximum = scope_policy["maximum_components"]
    raise "write_scope_component_count_exceeded" if maximum && candidate_scope.length > maximum
    artifact_scope = scope_policy.fetch("artifact_write_scope", route.fetch("artifact_write_scope", []))

    adoption_preconditions_met = true
    if task_class == "repository adoption"
      adoption = selectors.fetch("repository_adoption_policy")
      operation = context["adoption_operation"]
      raise "unsupported_repository_adoption_operation" unless adoption.fetch("operations").include?(operation)
      baseline = context[adoption.fetch("target_baseline_context_key")]
      raise "repository_adoption_baseline_required" unless baseline.to_s.match?(SHA_PATTERN)
      raise "repository_adoption_target_ambiguous" unless affected.length == 1 && candidate_scope.length == 1 && candidate_scope == affected
      raise "unsupported_repository_adoption_target" unless (candidate_scope - adoption.fetch("allowed_components")).empty?
      requested_paths = context.fetch("requested_write_paths", [])
      raise "repository_adoption_write_paths_must_be_array" unless requested_paths.is_a?(Array)
      invalid_paths = requested_paths.reject { |path| adoption_path_allowed?(path, adoption) }
      raise "repository_adoption_runtime_source_write_requested: #{invalid_paths.join(',')}" unless invalid_paths.empty?
      clean_target = context[adoption.fetch("target_clean_context_key")] == true
      owner_change_handling = !context[adoption.fetch("owner_controlled_changes_context_key")].to_s.strip.empty?
      evidence_complete = adoption.fetch("required_preflight_evidence").all? { |key| context[key] == true }
      adoption_preconditions_met = (clean_target || owner_change_handling) && evidence_complete
    end
    agents.concat(profile.fetch("add_agents", []))
    agents -= profile.fetch("remove_agents", [])
    order = selectors.fetch("agent_order")
    agents = agents.uniq.sort_by { |agent| order.index(agent) || raise("missing_agent_order: #{agent}") }

    directory = agent_directory || if File.basename(File.dirname(root)) == "yoro-os"
                                   File.expand_path("../../agents", root)
                                 else
                                   File.join(File.dirname(root), ".codex/agents")
                                 end
    available = concrete_agents(directory)
    missing = agents - available
    raise "missing_concrete_agent: #{missing.join(',')}" unless missing.empty?

    risk_requirements = risk_flags.map { |flag| routing.fetch("risk_overrides").fetch(flag, {}) }
    gates = (profile.fetch("required_gates") + risk_requirements.flat_map { |requirement| requirement.fetch("add_tests", []) }).uniq
    stops = (profile.fetch("stop_conditions") + risk_requirements.flat_map { |requirement| requirement.fetch("stop_if", []) }).uniq
    if task_class == "repository adoption"
      stops.concat(%w[repository_adoption_authority_missing repository_adoption_target_ambiguous repository_adoption_runtime_source_write_requested repository_adoption_generated_content_drift repository_adoption_preconditions_incomplete]).uniq!
    end
    approvals = (profile.fetch("required_approvals", []) + risk_requirements.flat_map { |requirement| requirement.fetch("add_approvals", []) }).uniq
    route.fetch("human_approvals", []).each do |approval|
      selector = selectors.fetch("approval_selectors", {})[approval]
      if selector
        approvals << approval if selector.fetch("values").include?(context[selector.fetch("context_key")])
      else
        approvals << approval
      end
    end
    approvals.uniq!
    reviewers = (profile.fetch("required_reviewers") + (agents & %w[architect contract-auditor security-reviewer release-reviewer reviewer])).uniq
    known_approvals = selectors.fetch("owner_requirements")
    unknown_approvals = approvals - known_approvals.keys
    raise "unknown_owner_requirement: #{unknown_approvals.join(',')}" unless unknown_approvals.empty?
    assignments = context.fetch("owner_assignments", {})
    unresolved = approvals.reject do |approval|
      approval_assignment_resolved?(approval, assignments[approval], known_approvals.fetch(approval), candidate_scope, declared_components, context)
    end

    authorization_state = unresolved.empty? ? "authorized" : "blocked_pending_approvals"
    if task_class == "repository adoption" && context[selectors.fetch("repository_adoption_policy").fetch("generated_drift_context_key")] == true
      authorization_state = "blocked_generated_content_drift"
      stops << "repository_adoption_generated_content_drift"
    end
    if task_class == "repository adoption" && !adoption_preconditions_met
      authorization_state = "blocked_adoption_preconditions" if authorization_state == "authorized"
      stops << "repository_adoption_preconditions_incomplete"
    end
    environment_scope = context[selectors.fetch("repository_adoption_policy").fetch("environment_boundary_context_key")]
    if environment_scope
      environment_scope = repository_scope(environment_scope, declared_components, "environment_writable_repositories")
      authorization_state = "blocked_environment_boundary" unless (candidate_scope - environment_scope).empty?
    end
    if candidate_scope.any? && (environment_scope.nil? || !(candidate_scope - environment_scope).empty?)
      authorization_state = "blocked_environment_boundary" if authorization_state == "authorized"
      stops << "execution_environment_write_scope_mismatch"
    end
    if task_class == "repository adoption"
      exact_environment = environment_scope && environment_scope.sort == candidate_scope.sort
      unless exact_environment
        authorization_state = "blocked_environment_boundary" if authorization_state == "authorized"
        stops << "execution_environment_write_scope_mismatch"
      end
    end
    effective_scope = authorization_state == "authorized" ? candidate_scope.dup : []

    tier = if (agents & %w[architect contract-auditor security-reviewer release-reviewer]).empty?
             profile.fetch("capability_tier", route.fetch("capability_tier"))
           else
             "high_reasoning"
           end
    model_mapping = load_yaml(File.join(root, "model-routing.yaml")).fetch("tiers")
    raise "capability_tier_unavailable: #{tier}" unless model_mapping[tier].is_a?(Hash) && !model_mapping[tier]["current_model"].to_s.empty?
    available_tiers = context.fetch("available_capability_tiers", model_mapping.keys)
    raise "capability_tier_unavailable: #{tier}" unless available_tiers.include?(tier)

    {
      "primary_task_class" => task_class,
      "adoption_operation" => task_class == "repository adoption" ? context.fetch("adoption_operation") : "not_applicable",
      "risk_flags" => risk_flags,
      "agent_sequence" => agents,
      "capability_tier" => tier,
      "candidate_write_scope" => candidate_scope,
      "write_authorization_state" => authorization_state,
      "effective_write_scope" => effective_scope,
      "artifact_write_scope" => artifact_scope,
      "write_scope" => effective_scope.dup,
      "required_gates" => gates,
      "required_reviewers" => reviewers,
      "required_approvals" => approvals.dup,
      "stop_conditions" => stops,
      "prohibited_behavior" => profile.fetch("prohibited_behavior"),
      "owner_resolution" => {
        "required_for_this_task" => approvals.dup,
        "unresolved_required" => unresolved.dup,
        "metadata_only_unresolved_is_blocking" => false
      },
      "blocked_before_protected_write" => authorization_state != "authorized",
      "environment_write_scope_verified" => environment_scope ? (effective_scope - environment_scope).empty? : false,
      "environment_write_scope_precondition" => "effective write scope must be within observed environment/UI permission boundaries; repository adoption requires an exact single-target boundary"
    }
  end
end

if $PROGRAM_NAME == __FILE__
  input_path = ARGV.shift
  abort "usage: #{File.basename($PROGRAM_NAME)} INPUT.yaml [AGENT_DIRECTORY]" unless input_path
  input = YoroRouteResolver.load_yaml(input_path)
  puts YAML.dump(YoroRouteResolver.resolve(input, agent_directory: ARGV.shift))
end
